<?php

namespace RKR\PHPStan\Common;

/**
 * @template TKeys of array<string, mixed>
 * @template TValues of array<string, mixed>
 * @template TExtras of array<string, mixed>
 */
interface GenericRowBuilder {
	/**
	 * @template TName of literal-string
	 * @param TName $name
	 * @return self<\rkrAddKey<TKeys, TName, string|null>, TValues, TExtras>
	 */
	public function addStringKey(string $name): self;

	/**
	 * @template TName of literal-string
	 * @param TName $name
	 * @return self<TKeys, \rkrAddKey<TValues, TName, int|null>, TExtras>
	 */
	public function addIntValue(string $name): self;

	/**
	 * @template TName of literal-string
	 * @param TName $name
	 * @return self<TKeys, TValues, \rkrAddKey<TExtras, TName, string|null>>
	 */
	public function addStringExtra(string $name): self;

	/** @return \rkrMerge<TKeys, \rkrMerge<TValues, TExtras>> */
	public function row(): array;
}
