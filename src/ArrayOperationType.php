<?php

namespace RKR\PHPStan;

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\ArrayType;
use PHPStan\Type\CompoundType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\LateResolvableType;
use PHPStan\Type\Traits\LateResolvableTypeTrait;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\VerbosityLevel;

/** Keeps operands intact until PHPStan substitutes concrete template arguments. */
// PHPStan has no public base class for deferred array operations. These internal
// interfaces/traits provide its late-resolution protocol; regression tests cover it.
final class ArrayOperationType implements CompoundType, LateResolvableType { // @phpstan-ignore phpstanApi.interface (Custom deferred types require the CompoundType protocol.)
	use LateResolvableTypeTrait; // @phpstan-ignore phpstanApi.trait (Delegate type queries through PHPStan's late-resolution protocol.)

	/**
	 * @param 'rkrMerge'|'rkrAddKey'|'rkrRemoveKey' $operation
	 * @param non-empty-list<Type> $operands
	 */
	public function __construct(
		private readonly string $operation,
		private readonly array $operands,
	) {}

	public function getReferencedClasses(): array {
		$result = [];
		foreach($this->operands as $operand) {
			array_push($result, ...$operand->getReferencedClasses());
		}
		return $result;
	}

	public function getReferencedTemplateTypes(TemplateTypeVariance $positionVariance): array {
		$result = [];
		foreach($this->operands as $operand) {
			array_push($result, ...$operand->getReferencedTemplateTypes($positionVariance));
		}
		return $result;
	}

	public function equals(Type $type): bool {
		if(!$type instanceof self || $this->operation !== $type->operation || count($this->operands) !== count($type->operands)) {
			return false;
		}
		foreach($this->operands as $index => $operand) {
			if(!$operand->equals($type->operands[$index])) {
				return false;
			}
		}
		return true;
	}

	public function describe(VerbosityLevel $level): string {
		return sprintf('%s<%s>', $this->operation, implode(', ', array_map(static fn(Type $type): string => $type->describe($level), $this->operands)));
	}

	public function isResolvable(): bool {
		foreach($this->operands as $operand) {
			if(TypeUtils::containsTemplateType($operand)) {
				return false;
			}
		}
		return true;
	}

	protected function getResult(): Type {
		return match($this->operation) {
			'rkrMerge' => $this->merge(),
			'rkrAddKey' => $this->addKey(),
			'rkrRemoveKey' => $this->removeKeys(),
		};
	}

	public function traverse(callable $cb): Type {
		$operands = array_map($cb, $this->operands);
		return $operands === $this->operands ? $this : new self($this->operation, $operands);
	}

	public function traverseSimultaneously(Type $right, callable $cb): Type {
		if(!$right instanceof self || $this->operation !== $right->operation || count($this->operands) !== count($right->operands)) {
			return $this;
		}
		$operands = [];
		foreach($this->operands as $index => $operand) {
			$operands[] = $cb($operand, $right->operands[$index]);
		}
		return $operands === $this->operands ? $this : new self($this->operation, $operands);
	}

	public function generalize(GeneralizePrecision $precision): Type {
		return $this->traverse(static fn(Type $type): Type => $type->generalize($precision));
	}

	public function toPhpDocNode(): TypeNode {
		return new GenericTypeNode(new IdentifierTypeNode($this->operation), array_map(static fn(Type $type): TypeNode => $type->toPhpDocNode(), $this->operands));
	}

	private function merge(): Type {
		$result = $this->operands[0];
		foreach(array_slice($this->operands, 1) as $right) {
			if(!$result->isArray()->yes() || !$right->isArray()->yes()) {
				return new ErrorType();
			}
			$leftArrays = $result->getConstantArrays();
			$rightArrays = $right->getConstantArrays();
			if($leftArrays === [] || $rightArrays === []) {
				$result = new ArrayType(
					TypeCombinator::union($result->getIterableKeyType(), $right->getIterableKeyType()),
					TypeCombinator::union($result->getIterableValueType(), $right->getIterableValueType()),
				);
				continue;
			}
			$merged = [];
			foreach($leftArrays as $leftArray) {
				foreach($rightArrays as $rightArray) {
					$builder = ConstantArrayTypeBuilder::createEmpty();
					$builder->disableArrayDegradation();
					$this->appendArray($builder, $leftArray);
					$this->appendArray($builder, $rightArray);
					$merged[] = $builder->getArray();
				}
			}
			$result = TypeCombinator::union(...$merged);
		}
		return $result;
	}

	private function addKey(): Type {
		[$subject, $key, $value] = $this->operands;
		if($subject->isArray()->no()) {
			return new ErrorType();
		}
		$keys = $this->constantKeys($key);
		if(count($keys) !== 1) {
			// Template-bound checks can substitute a broad string/int before the call
			// supplies a literal key. Keep a sound array bound for that check.
			if($keys === [] && ($key->isString()->yes() || $key->isInteger()->yes())) {
				return new ArrayType(TypeCombinator::union($subject->getIterableKeyType(), $key), TypeCombinator::union($subject->getIterableValueType(), $value));
			}
			return new ErrorType();
		}
		$key = $keys[0];
		$arrays = $subject->getConstantArrays();
		if($arrays === []) {
			return new ArrayType(TypeCombinator::union($subject->getIterableKeyType(), $key), TypeCombinator::union($subject->getIterableValueType(), $value));
		}
		$result = [];
		foreach($arrays as $array) {
			$builder = ConstantArrayTypeBuilder::createEmpty();
			$builder->disableArrayDegradation();
			$this->appendArray($builder, $array);
			$builder->setOffsetValueType($key, $value, false);
			$result[] = $builder->getArray();
		}
		return TypeCombinator::union(...$result);
	}

	private function removeKeys(): Type {
		$subject = $this->operands[0];
		if($subject->isArray()->no()) {
			return new ErrorType();
		}
		$keys = [];
		foreach(array_slice($this->operands, 1) as $operand) {
			foreach($this->constantKeys($operand) as $key) {
				$keys[] = $key->getValue();
			}
		}
		$arrays = $subject->getConstantArrays();
		if($keys === [] || $arrays === []) {
			return $subject;
		}
		$result = [];
		foreach($arrays as $array) {
			$builder = ConstantArrayTypeBuilder::createEmpty();
			$builder->disableArrayDegradation();
			foreach($array->getKeyTypes() as $index => $key) {
				if(!in_array($key->getValue(), $keys, true)) {
					$builder->setOffsetValueType($key, $array->getValueTypes()[$index], $array->isOptionalKey($index));
				}
			}
			$result[] = $builder->getArray();
		}
		return TypeCombinator::union(...$result);
	}

	private function appendArray(ConstantArrayTypeBuilder $builder, ConstantArrayType $array): void {
		foreach($array->getKeyTypes() as $index => $key) {
			$builder->setOffsetValueType($key, $array->getValueTypes()[$index], $array->isOptionalKey($index));
		}
	}

	/** @return list<ConstantStringType|ConstantIntegerType> */
	private function constantKeys(Type $type): array {
		$keys = [];
		foreach($type->getConstantScalarTypes() as $constant) {
			$key = $constant->toArrayKey();
			foreach([...$key->getConstantStrings(), ...TypeUtils::getConstantIntegers($key)] as $constantKey) {
				$keys[get_class($constantKey) . ':' . $constantKey->getValue()] = $constantKey;
			}
		}
		return array_values($keys);
	}
}
