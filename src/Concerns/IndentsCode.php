<?php

namespace Galahad\LaravelFauxGenerics\Concerns;

use Illuminate\Support\Collection;

trait IndentsCode
{
	protected function indent(string $input, int $depth = 1) : string
	{
		return str_replace("\n", "\n".str_repeat("\t", $depth), $input);
	}
	
	protected function indentDocBlock(Collection $collection, int $depth = 1) : string
	{
		return $this->indent($collection->implode("\n * "), $depth);
	}
}
