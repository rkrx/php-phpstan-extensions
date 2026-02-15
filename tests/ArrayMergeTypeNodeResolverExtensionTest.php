<?php

namespace RKR\PHPStan;

use PHPUnit\Framework\Attributes\DataProvider;
use function PHPStan\Testing\assertType;

use PHPStan\Testing\TypeInferenceTestCase;
use RKR\PHPStan\Common\Merger;

class ArrayMergeTypeNodeResolverExtensionTest extends TypeInferenceTestCase {
	#[DataProvider('dataFileAsserts')]
	public function test(string $assertType, string $file, mixed ...$args): void {
		$this->assertFileAsserts($assertType, $file, ...$args);
		
		#$x = ['a' => 123];
		#$y = ['b' => 567];
		
		#/** @var Merger<array{a: int}, array{b: int}> $merger */
		#$merger = new Merger();
		#$z = $merger->merge($x, $y);
		#
		#assertType('array{a: int, b: string}', $z);
		#
		#print_r($node);
	}
	
	/**
	 * @return iterable<mixed>
	 */
	public static function dataFileAsserts(): iterable {
		yield from self::gatherAssertTypes(__DIR__ . '/Common/Merger.php');
		yield from self::gatherAssertTypes(__DIR__ . '/Common/CustomOperators.php');
	}
	
	public static function getAdditionalConfigFiles(): array {
		return [__DIR__ . '/../extension.neon'];
	}
}
