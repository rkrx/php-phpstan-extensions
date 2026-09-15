<?php

namespace RKR\PHPStan;

use InvalidArgumentException;
use PHPStan\Analyser\NameScope;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeNodeResolverAwareExtension;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;

final class TypeNodeResolverExtensions implements TypeNodeResolverExtension, TypeNodeResolverAwareExtension {
	private TypeNodeResolver $typeNodeResolver;

	public function setTypeNodeResolver(TypeNodeResolver $typeNodeResolver): void {
		$this->typeNodeResolver = $typeNodeResolver;
	}

	public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type {
		if(!$typeNode instanceof GenericTypeNode) {
			return null;
		}
		$name = strtolower(ltrim($typeNode->type->name, '\\'));
		$nodes = $typeNode->genericTypes;
		if(in_array($name, ['rkr\\merge', 'rkrmerge', 'rkr-merge'], true)) {
			$operation = 'rkrMerge';
			if(count($nodes) < 2) {
				throw new InvalidArgumentException('rkrMerge requires at least two generic types.');
			}
		} elseif(preg_match('/^rkr(?:\\\\)?merge(\d+)$/', $name, $matches) === 1) {
			$operation = 'rkrMerge';
			$expected = (int) $matches[1];
			if(count($nodes) !== $expected || $nodes === []) {
				throw new InvalidArgumentException(sprintf('rkrMerge%d requires exactly %d generic types.', $expected, $expected));
			}
		} elseif(in_array($name, ['rkr\\addkey', 'rkraddkey'], true)) {
			$operation = 'rkrAddKey';
			if(count($nodes) !== 3) {
				throw new InvalidArgumentException('rkrAddKey requires exactly three generic types.');
			}
		} elseif(in_array($name, ['rkr\\removekey', 'rkrremovekey', 'rkr-remove-key'], true)) {
			$operation = 'rkrRemoveKey';
			if(count($nodes) < 2) {
				throw new InvalidArgumentException('rkrRemoveKey requires an array type and at least one key.');
			}
		} else {
			return null;
		}

		$operands = [];
		foreach($nodes as $index => $node) {
			$isKey = ($operation === 'rkrAddKey' && $index === 1) || ($operation === 'rkrRemoveKey' && $index > 0);
			if($isKey && $node instanceof IdentifierTypeNode && $nameScope->resolveTemplateTypeName($node->name) === null) {
				// Bare identifiers are literal keys; template names must stay templates.
				$operands[] = new ConstantStringType($node->name);
			} else {
				$operands[] = $this->typeNodeResolver->resolve($node, $nameScope);
			}
		}

		$type = new ArrayOperationType($operation, $operands);
		return $type->isResolvable() ? $type->resolve() : $type;
	}
}
