<?php

namespace Kir\PhpStan\Common;

use function PHPStan\Testing\assertType;

/**
 * @template T of array<string, mixed>
 * @template U of array<string, mixed>
 */
class Merger {
	/**
	 * @param T $a
	 * @param U $b
	 *
	 * @return T&U
	 */
	function merge(array $a, array $b): array {
		return array_merge($a, $b);
	}
}

$x = ['a' => 123];
$y = ['b' => '567'];

/** @var Merger<array{a: int}, array{b: string}> $merger */
$merger = new Merger();
$z = $merger->merge($x, $y);

assertType('array{a: int, b: string}', $z);
