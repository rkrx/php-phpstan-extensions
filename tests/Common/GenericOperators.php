<?php

namespace RKR\PHPStan\Common;

use function PHPStan\Testing\assertType;

/**
 * @param GenericRowBuilder<array{}, array{}, array{}> $builder
 * @param array{ean: string, stock?: int} $left
 * @param array{stock: float, name: string} $right
 */
function checkGenericOperators(GenericRowBuilder $builder, GenericArrayOperations $operations, array $left, array $right): void {
	$first = $builder->addStringKey('ean');
	assertType('RKR\\PHPStan\\Common\\GenericRowBuilder<array{ean: string|null}, array{}, array{}>', $first);
	$second = $first->addIntValue('stock')->addStringExtra('filename');
	assertType('array{ean: string|null, stock: int|null, filename: string|null}', $second->row());
	assertType('array{ean: string|null, stock: int|null, filename: string|null}', $second->row());
	assertType('array{ean: string, stock: float, name: string}', $operations->merge($left, $right));
	assertType('array{ean: string}', $operations->remove($left, 'stock'));
	assertType('array{stock?: int}', $operations->remove($left, 'ean'));
	assertType('array{ean: string, stock?: int}', $operations->remove($left, 'missing'));
}

/** @phpstan-var \rkrAddKey<array{a: int}, 'b', string|null> $added */
$added = [];
assertType('array{a: int, b: string|null}', $added);
/** @phpstan-var \rkrAddKey<array{a?: int}, 'a', string> $overwritten */
$overwritten = [];
assertType('array{a: string}', $overwritten);
/** @phpstan-var \rkrMerge3<array{a: int}, array{b: string}, array{c?: bool}> $triple */
$triple = [];
assertType('array{a: int, b: string, c?: bool}', $triple);
/** @phpstan-var \rkrRemoveKey<array{0: string, a?: int, b: bool}, 0|'b'> $removed */
$removed = [];
assertType('array{a?: int}', $removed);
