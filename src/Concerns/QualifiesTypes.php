<?php

namespace Galahad\LaravelFauxGenerics\Concerns;

trait QualifiesTypes
{
	protected function qualifyType(string $type) : string
	{
		$keywords = [
			'$this',
			'void',
			'mixed',
			'null',
			'self',
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
