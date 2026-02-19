<?php

namespace RKR\PHPStan\Common;

use function PHPStan\Testing\assertType;

class Merger {
	/**
	 * @param array{a: int} $a
	 * @param array{b: string} $b
	 *
	 * @return \rkrMerge<array{a: int}, array{b: string}>
	 */
	function merge(array $a, array $b): array {
		return array_merge($a, $b);
	}
}

$x = ['a' => 123];
$y = ['b' => '567'];

$merger = new Merger();
$z = $merger->merge($x, $y);

assertType('array{a: int, b: string}', $z);
