<?php

namespace RKR\PHPStan;

use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\TestCase;

final class ArrayOperationTypeTest extends TestCase {
	public function testTraversalPreservesOriginalAndInvalidatesCachedResult(): void {
		$key = new StringType();
		$operation = new ArrayOperationType('rkrAddKey', [new ConstantArrayType([], []), $key, new IntegerType()]);
		self::assertTrue($operation->isResolvable());
		self::assertSame('array<string, int>', $operation->resolve()->describe(VerbosityLevel::precise()));
		$resolved = $operation->traverse(static fn(Type $type): Type => $type === $key ? new ConstantStringType('stock') : $type);
		self::assertInstanceOf(ArrayOperationType::class, $resolved);
		self::assertTrue($resolved->isResolvable());
		self::assertSame('array{stock: int}', $resolved->resolve()->describe(VerbosityLevel::precise()));
		self::assertTrue($operation->isResolvable());
		self::assertSame($operation, $operation->traverse(static fn(Type $type): Type => $type));
	}

	public function testSimultaneousTraversalAndEquality(): void {
		$left = new ArrayOperationType('rkrAddKey', [new ConstantArrayType([], []), new ConstantStringType('ean'), new StringType()]);
		$right = new ArrayOperationType('rkrAddKey', [new ConstantArrayType([], []), new ConstantStringType('stock'), new IntegerType()]);
		self::assertFalse($left->equals($right));
		self::assertTrue($left->equals(clone $left));
		$resolved = $left->traverseSimultaneously($right, static fn(Type $a, Type $b): Type => $b);
		self::assertTrue($resolved->equals($right));
		self::assertSame($left, $left->traverseSimultaneously(new StringType(), static fn(Type $a, Type $b): Type => $b));
	}

	public function testInvalidArrayOperandRemainsAnError(): void {
		foreach(['rkrMerge', 'rkrAddKey', 'rkrRemoveKey'] as $operation) {
			$type = new ArrayOperationType($operation, [new IntegerType(), new ConstantStringType('ean'), new StringType()]);
			self::assertTrue($type->resolve()->equals(new ErrorType()));
		}
	}

	public function testMixedValuesAreNotNarrowedByAddingAKey(): void {
		$type = new ArrayOperationType('rkrAddKey', [new ArrayType(new StringType(), new MixedType()), new ConstantStringType('stock'), new IntegerType()]);
		self::assertSame('array<string, mixed>', $type->resolve()->describe(VerbosityLevel::precise()));
	}
}
