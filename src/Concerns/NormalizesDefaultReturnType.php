<?php

namespace Galahad\LaravelFauxGenerics\Concerns;

trait NormalizesDefaultReturnType
{
	protected function normalizeDefaultValue($default) : string
	{
		$default = var_export($default, true);
		
		// Map null
		if ('NULL' === $default) {
			return 'null';
		}
		
		// Normalize arrays
		$default = preg_replace('/\n\s*/', ' ', $default);
		$default = preg_replace('/^\s*array\s*\(\s*(.*?)\s*\)\s*$/i', '[$1]', $default);
		$default = preg_replace('/,\]$/', ']', $default);
		$default = preg_replace('/^\[0\s*=>\s*(.*)\]$/', '[$1]', $default);
		
		return $default;
	}
}
