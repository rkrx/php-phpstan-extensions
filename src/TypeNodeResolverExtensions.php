<?php

namespace RKR\PHPStan;

use InvalidArgumentException;
use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeNodeResolverAwareExtension;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

final class TypeNodeResolverExtensions implements TypeNodeResolverExtension, TypeNodeResolverAwareExtension {
	private TypeNodeResolver $typeNodeResolver;

	public function setTypeNodeResolver(TypeNodeResolver $typeNodeResolver): void {
		$this->typeNodeResolver = $typeNodeResolver;
	}

	public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type {
		if(!$typeNode instanceof GenericTypeNode) {
			return null;
		}

		$typeName = ltrim($typeNode->type->name, '\\');
		$typeNameLower = strtolower($typeName);

		if($typeNameLower === 'rkr\\merge' || $typeNameLower === 'rkrmerge' || $typeNameLower === 'rkr-merge') {
			return $this->resolveMerge($typeNode, $nameScope);
		}

		if(preg_match('/^rkr\\\\merge(\\d+)$/', $typeNameLower, $matches) === 1 || preg_match('/^rkrmerge(\\d+)$/', $typeNameLower, $matches) === 1) {
			$expected = (int) $matches[1];
			return $this->resolveMerge($typeNode, $nameScope, $expected);
		}

		if($typeNameLower === 'rkr\\addkey' || $typeNameLower === 'rkraddkey') {
			return $this->resolveAddKey($typeNode, $nameScope);
		}

		if($typeNameLower === 'rkr\\removekey' || $typeNameLower === 'rkrremovekey' || $typeNameLower === 'rkr-remove-key') {
			return $this->resolveRemoveKey($typeNode, $nameScope);
		}

		return null;
	}

	private function resolveMerge(GenericTypeNode $typeNode, NameScope $nameScope, ?int $expectedCount = null): Type {
		$genericTypes = $typeNode->genericTypes;
		if($expectedCount !== null) {
			if(count($genericTypes) !== $expectedCount) {
				throw new InvalidArgumentException(sprintf('rkr\\merge%d requires exactly %d generic types.', $expectedCount, $expectedCount));
			}
		} elseif(count($genericTypes) < 2) {
			throw new InvalidArgumentException('rkr\\merge requires at least two generic types.');
		}

		$resolvedTypes = array_map(
			fn(TypeNode $node): Type => $this->unwrapTemplateType($this->typeNodeResolver->resolve($node, $nameScope), $nameScope),
			$genericTypes
		);

		$result = array_shift($resolvedTypes);
		if($result === null) {
			return new ErrorType();
		}

		foreach($resolvedTypes as $nextType) {
			$result = $this->mergeTypes($result, $nextType, $nameScope);
		}

		return $result;
	}

	private function mergeTypes(Type $left, Type $right, NameScope $nameScope): Type {
		$left = $this->unwrapTemplateType($left, $nameScope);
		$right = $this->unwrapTemplateType($right, $nameScope);

		$leftConstantArrays = $left->getConstantArrays();
		$rightConstantArrays = $right->getConstantArrays();
		if(count($leftConstantArrays) > 0 && count($rightConstantArrays) > 0) {
			return $this->mergeConstantArrays($leftConstantArrays, $rightConstantArrays);
		}

		if($left->isArray()->yes() && $right->isArray()->yes()) {
			return new ArrayType(
				TypeCombinator::union($left->getIterableKeyType(), $right->getIterableKeyType()),
				TypeCombinator::union($left->getIterableValueType(), $right->getIterableValueType())
			);
		}

		return new ErrorType();
	}

	/**
	 * @param ConstantArrayType[] $leftArrays
	 * @param ConstantArrayType[] $rightArrays
	 */
	private function mergeConstantArrays(array $leftArrays, array $rightArrays): Type {
		$mergedTypes = [];
		foreach($leftArrays as $left) {
			foreach($rightArrays as $right) {
				$builder = ConstantArrayTypeBuilder::createEmpty();
				$builder->disableArrayDegradation();
				$this->appendConstantArray($builder, $left);
				$this->appendConstantArray($builder, $right);
				$mergedTypes[] = $builder->getArray();
			}
		}

		return TypeCombinator::union(...$mergedTypes);
	}

	private function appendConstantArray(ConstantArrayTypeBuilder $builder, ConstantArrayType $arrayType): void {
		foreach($arrayType->getKeyTypes() as $i => $keyType) {
			$builder->setOffsetValueType(
				$keyType,
				$arrayType->getValueTypes()[$i],
				$arrayType->isOptionalKey($i)
			);
		}
	}

	private function resolveRemoveKey(GenericTypeNode $typeNode, NameScope $nameScope): Type {
		$genericTypes = $typeNode->genericTypes;
		if(count($genericTypes) < 2) {
			throw new InvalidArgumentException('rkr\\removeKey requires an array type and at least one key.');
		}

		$arrayTypeNode = array_shift($genericTypes);
		$arrayType = $this->unwrapTemplateType($this->typeNodeResolver->resolve($arrayTypeNode, $nameScope), $nameScope);
		if($arrayType->isArray()->no()) {
			return new ErrorType();
		}

		$keyTypes = $this->resolveKeyTypes($genericTypes, $nameScope);
		if($keyTypes === []) {
			return $arrayType;
		}

		$constantArrays = $arrayType->getConstantArrays();
		if($constantArrays === []) {
			return $arrayType;
		}

		$removeValues = $this->keyTypeValues($keyTypes);
		$updatedTypes = [];
		foreach($constantArrays as $array) {
			$updatedTypes[] = $this->removeKeysFromConstantArray($array, $removeValues);
		}

		return TypeCombinator::union(...$updatedTypes);
	}

	private function resolveAddKey(GenericTypeNode $typeNode, NameScope $nameScope): Type {
		$genericTypes = $typeNode->genericTypes;
		if(count($genericTypes) !== 3) {
			throw new InvalidArgumentException('rkr\\addKey requires exactly three generic types.');
		}

		$subjectNode = $genericTypes[0];
		$keyNode = $genericTypes[1];
		$valueNode = $genericTypes[2];

		$subjectType = $this->unwrapTemplateType($this->typeNodeResolver->resolve($subjectNode, $nameScope), $nameScope);
		if($subjectType->isArray()->no()) {
			return new ErrorType();
		}

		$keyTypes = $this->resolveKeyType($keyNode, $nameScope);
		if(count($keyTypes) !== 1) {
			return new ErrorType();
		}
		$keyType = $keyTypes[0];

		$valueType = $this->unwrapTemplateType($this->typeNodeResolver->resolve($valueNode, $nameScope), $nameScope);

		$constantArrays = $subjectType->getConstantArrays();
		if($constantArrays === []) {
			return new ArrayType(
				TypeCombinator::union($subjectType->getIterableKeyType(), $keyType),
				TypeCombinator::union($subjectType->getIterableValueType(), $valueType)
			);
		}

		$updatedTypes = [];
		foreach($constantArrays as $array) {
			$builder = ConstantArrayTypeBuilder::createEmpty();
			$builder->disableArrayDegradation();
			$this->appendConstantArray($builder, $array);
			$builder->setOffsetValueType($keyType, $valueType, false);
			$updatedTypes[] = $builder->getArray();
		}

		return TypeCombinator::union(...$updatedTypes);
	}

	/**
	 * @param TypeNode[] $keyNodes
	 * @return array<int, ConstantStringType|ConstantIntegerType>
	 */
	private function resolveKeyTypes(array $keyNodes, NameScope $nameScope): array {
		$keys = [];
		foreach($keyNodes as $keyNode) {
			foreach($this->resolveKeyType($keyNode, $nameScope) as $keyType) {
				$keys[] = $keyType;
			}
		}

		return $this->dedupeKeyTypes($keys);
	}

	/**
	 * @return array<int, ConstantStringType|ConstantIntegerType>
	 */
	private function resolveKeyType(TypeNode $keyNode, NameScope $nameScope): array {
		if($keyNode instanceof IdentifierTypeNode) {
			return [new ConstantStringType($keyNode->name)];
		}

		if($keyNode instanceof ConstTypeNode) {
			$constExpr = $keyNode->constExpr;
			if($constExpr instanceof ConstExprStringNode) {
				return [new ConstantStringType($constExpr->value)];
			}
			if($constExpr instanceof ConstExprIntegerNode) {
				return [new ConstantIntegerType((int) $constExpr->value)];
			}
		}

		$resolved = $this->typeNodeResolver->resolve($keyNode, $nameScope);
		$resolved = $this->unwrapTemplateType($resolved, $nameScope);

		return $this->extractConstantKeyTypes($resolved);
	}

	/**
	 * @return array<int, ConstantStringType|ConstantIntegerType>
	 */
	private function extractConstantKeyTypes(Type $type): array {
		$keys = [];
		foreach($type->getConstantStrings() as $stringType) {
			$keys[] = $stringType;
		}

		foreach($type->getConstantScalarTypes() as $scalarType) {
			$arrayKeyType = $scalarType->toArrayKey();
			foreach($arrayKeyType->getConstantStrings() as $stringType) {
				$keys[] = $stringType;
			}
			foreach(\PHPStan\Type\TypeUtils::getConstantIntegers($arrayKeyType) as $intType) {
				$keys[] = $intType;
			}
		}

		return $this->dedupeKeyTypes($keys);
	}

	/**
	 * @param array<int, ConstantStringType|ConstantIntegerType> $keyTypes
	 * @return array<int, ConstantStringType|ConstantIntegerType>
	 */
	private function dedupeKeyTypes(array $keyTypes): array {
		$deduped = [];
		$seen = [];
		foreach($keyTypes as $keyType) {
			$value = $keyType->getValue();
			$id = (is_string($value) ? 's:' : 'i:') . $value;
			if(isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$deduped[] = $keyType;
		}

		return $deduped;
	}

	/**
	 * @param array<int, ConstantStringType|ConstantIntegerType> $keyTypes
	 * @return array<int, string|int>
	 */
	private function keyTypeValues(array $keyTypes): array {
		$values = [];
		foreach($keyTypes as $keyType) {
			$values[] = $keyType->getValue();
		}

		return $values;
	}

	/**
	 * @param array<int, string|int> $removeValues
	 */
	private function removeKeysFromConstantArray(ConstantArrayType $arrayType, array $removeValues): Type {
		$builder = ConstantArrayTypeBuilder::createEmpty();
		$builder->disableArrayDegradation();

		foreach($arrayType->getKeyTypes() as $i => $keyType) {
			if(in_array($keyType->getValue(), $removeValues, true)) {
				continue;
			}

			$builder->setOffsetValueType(
				$keyType,
				$arrayType->getValueTypes()[$i],
				$arrayType->isOptionalKey($i)
			);
		}

		return $builder->getArray();
	}

	private function unwrapTemplateType(Type $type, NameScope $nameScope): Type {
		if($type instanceof TemplateType) {
			$resolved = $nameScope->resolveTemplateTypeName($type->getName());
			if($resolved !== null) {
				return $resolved;
			}

			return $type->getBound();
		}

		return $type;
	}
}
