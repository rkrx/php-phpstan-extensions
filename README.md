# rkr/phpstan-extensions

PHPStan type-node resolver extensions that add custom type functions for array merging, key addition, and key removal.

**Status**
- PHP: 8.2+
- PHPStan: ^2.1
- Stability: dev

**Installation**

```sh
composer require --dev rkr/phpstan-extensions
```

**Quick Start**

1. Register the extension in your PHPStan config:

```neon
includes:
	- vendor/rkr/phpstan-extensions/extension.neon
```

2. Use the custom type functions in PHPDoc:

```php
<?php

use function PHPStan\Testing\assertType;

/** @phpstan-var \rkrMerge<array{a: int}, array{b: string}> $merged */
$merged = ['a' => 1, 'b' => 'x'];

assertType('array{a: int, b: string}', $merged);

/** @phpstan-var \rkrAddKey<array{a: int}, 'b', array<string, mixed>> $added */
$added = ['a' => 1, 'b' => []];

/** @phpstan-var \rkrRemoveKey<array{a: int, b: string, c: float}, 'b'|'c'> $removed */
$removed = ['a' => 1];

assertType('array{a: int}', $removed);
```

**Usage**

### rkrMerge

`\rkrMerge<TLeft, TRight>` merges array types and preserves constant array shapes when possible.

For 3+ arrays, use the convenience aliases `\rkrMerge3` ... `\rkrMerge20`.

```php
/** @phpstan-var \rkrMerge<array{a: int}, array{b: string}> $value */
$value = ['a' => 1, 'b' => 'x'];

/** @phpstan-var \rkrMerge3<array{a: int}, array{b: string}, array{c: float}> $value3 */
$value3 = ['a' => 1, 'b' => 'x', 'c' => 1.5];
```

### rkrAddKey

`\rkrAddKey<TSubject, TKey, TValue>` adds a constant key to an array shape.

```php
/** @phpstan-var \rkrAddKey<array{a: int}, 'b', array<string, mixed>> $value */
$value = ['a' => 1, 'b' => []];
```

### rkrRemoveKey

`\rkrRemoveKey<TArray, TKey>` removes one or more keys from a constant array shape.

Pass multiple keys as a union of constant strings/integers (for example `'a'|'b'|3`).

```php
/** @phpstan-var \rkrRemoveKey<array{a: int, b: string, c: float}, 'b'|'c'> $value */
$value = ['a' => 1];
```

### Generic builders

All three operations preserve template arguments until PHPStan knows their concrete
values. This also works with nested operations and chained builder calls:

```php
/**
 * @template TRow of array<string, mixed>
 */
interface RowBuilder {
    /**
     * @template TName of literal-string
     * @param TName $name
     * @return self<\rkrAddKey<TRow, TName, string|null>>
     */
    public function addStringKey(string $name): self;

    /** @return TRow */
    public function row(): array;
}
```

Starting with `RowBuilder<array{}>`, `addStringKey('ean')->row()` resolves to
`array{ean: string|null}`. Template names used as keys remain templates; bare
non-template identifiers continue to represent literal keys.

**Public API**

- Type functions
- `rkrMerge<...>` and `rkrMerge3` ... `rkrMerge20`
  - Returns a merged array type. If both inputs are constant arrays, the result is a constant array shape union.
- `rkrAddKey<...>`
- `rkrRemoveKey<...>`
  - Returns the array type with the specified keys removed when the input is a constant array shape.

**Configuration**

Include the extension via `extension.neon`:

```neon
includes:
	- vendor/rkr/phpstan-extensions/extension.neon
```

Or register the service manually:

```neon
services:
	-
		class: RKR\PHPStan\TypeNodeResolverExtensions
		tags:
			- phpstan.phpDoc.typeNodeResolverExtension
```

**Error Handling**

- If `rkrMerge` receives non-array types, it resolves to an error type in PHPStan.
- If `rkrMerge3` ... `rkrMerge20` do not receive the exact number of generic types, they resolve to an error type in PHPStan.
- If `rkrAddKey` is given a non-array as the first generic type or multiple constant keys, it resolves to an error type.
- A generic key widened to `string` or `int` produces an array bound with the combined key and value types. Once a literal is available, the exact shape is retained.
- If `rkrRemoveKey` is given a non-array as the first generic type, it resolves to an error type.
- If keys cannot be resolved to constant strings or integers, the original array type is preserved.

**Testing**

```sh
composer test
composer run phpstan
```

The inference tests cover concrete shapes, template substitution, nested merges,
chained builders, overwrites, optional keys, and key removal. Deferred operations
use PHPStan's internal `CompoundType`/`LateResolvableTypeTrait` protocol because
there is no public base class for this kind of operation. The two corresponding
API-compatibility warnings are acknowledged locally in `ArrayOperationType`;
PHPStan upgrades must be checked with these tests. See PHPStan's
[backward compatibility promise](https://phpstan.org/developing-extensions/backward-compatibility-promise).

**Contributing**

Issues and pull requests are welcome. Please include a minimal reproduction and expected vs. actual behavior for type-related changes.

**License**

MIT
