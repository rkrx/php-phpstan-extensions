# rkr/phpstan-extensions

PHPStan type-node resolver extensions that add custom type functions for array merging and key removal.

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

/** @phpstan-var \rkr\merge<array{a: int}, array{b: string}> $merged */
$merged = ['a' => 1, 'b' => 'x'];

assertType('array{a: int, b: string}', $merged);

/** @phpstan-var \rkr\removeKey<array{a: int, b: string, c: float}, 'b'|'c'> $removed */
$removed = ['a' => 1];

assertType('array{a: int}', $removed);
```

**Usage**

### rkr\\merge

`\rkr\merge<TLeft, TRight>` merges array types and preserves constant array shapes when possible.

For 3+ arrays, use the convenience aliases `\rkr\merge3` ... `\rkr\merge20`.

```php
/** @phpstan-var \rkr\merge<array{a: int}, array{b: string}> $value */
$value = ['a' => 1, 'b' => 'x'];

/** @phpstan-var \rkr\merge3<array{a: int}, array{b: string}, array{c: float}> $value3 */
$value3 = ['a' => 1, 'b' => 'x', 'c' => 1.5];
```

### rkr\\removeKey

`\rkr\removeKey<TArray, TKey>` removes one or more keys from a constant array shape.

Pass multiple keys as a union of constant strings/integers (for example `'a'|'b'|3`).

```php
/** @phpstan-var \rkr\removeKey<array{a: int, b: string, c: float}, 'b'|'c'> $value */
$value = ['a' => 1];
```

**Public API**

- Type functions
- `rkr\\merge<...>` and `rkr\\merge3` ... `rkr\\merge20`
  - Returns a merged array type. If both inputs are constant arrays, the result is a constant array shape union.
- `rkr\\removeKey<...>`
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

- If `rkr\\merge` receives non-array types, it resolves to an error type in PHPStan.
- If `rkr\\merge3` ... `rkr\\merge20` do not receive the exact number of generic types, they resolve to an error type in PHPStan.
- If `rkr\\removeKey` is given a non-array as the first generic type, it resolves to an error type.
- If keys cannot be resolved to constant strings or integers, the original array type is preserved.

**Testing**

```sh
composer run phpstan
```

**Contributing**

Issues and pull requests are welcome. Please include a minimal reproduction and expected vs. actual behavior for type-related changes.

**License**

MIT
