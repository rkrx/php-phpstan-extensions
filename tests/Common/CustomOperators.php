<?php

use function PHPStan\Testing\assertType;

/** @var int $a */
$a = 1;
/** @var string $b */
$b = 'x';
/** @phpstan-var \rkr\merge<array{a: int}, array{b: string}> $merged */
$merged = [];

assertType('array{a: int, b: string}', $merged);

/** @phpstan-var \rkr\removeKey<array{a: int, b: string, c: float}, 'b'|'c'> $removed */
$removed = [];

assertType('array{a: int}', $removed);
