<?php

namespace RKR\PHPStan\Common;

interface GenericArrayOperations {
	/**
	 * @template TLeft of array<string, mixed>
	 * @template TRight of array<string, mixed>
	 * @param TLeft $left
	 * @param TRight $right
	 * @return \rkrMerge<TLeft, TRight>
	 */
	public function merge(array $left, array $right): array;

	/**
	 * @template T of array<array-key, mixed>
	 * @template TKey of array-key
	 * @param T $row
	 * @param TKey $key
	 * @return \rkrRemoveKey<T, TKey>
	 */
	public function remove(array $row, int|string $key): array;
}
