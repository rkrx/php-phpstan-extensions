<?php

use function PHPStan\Testing\assertType;

/** @phpstan-type TSomeType array{a: int} */
/** @phpstan-type TOtherType array{b: string} */
/** @phpstan-type TMergedType rkr-merge<TSomeType, TOtherType> */

/** @var TMergedType $merged */
$merged = ['a' => 1, 'b' => 'x'];

assertType('array{a: int, b: string}', $merged);

/** @phpstan-type TSomeTypeWithExtras array{a: int, b: string, c: float} */
/** @phpstan-type TRemovedType rkr-remove-key<TSomeTypeWithExtras, b, c> */

/** @var TRemovedType $removed */
$removed = ['a' => 1];

assertType('array{a: int}', $removed);
