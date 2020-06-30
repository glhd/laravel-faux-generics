<?php

namespace Galahad\LaravelFauxGenerics\Concerns;

use ReflectionType;

trait QualifiesTypes
{
	protected function qualifyType($type) : string
	{
		if ($type instanceof ReflectionType) {
			$type = $type->getName();
		}
		
		$keywords = [
			'$this',
			'void',
			'mixed',
			'null',
			'self',
			'static',
			'object',
			'array',
			'callable',
			'bool',
			'float',
			'int',
			'string',
			'iterable',
			'object',
		];
		
		if (in_array($type, $keywords, true)) {
			return $type;
		}
		
		return '\\'.ltrim($type, '\\');
	}
}
