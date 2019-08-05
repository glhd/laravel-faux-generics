<?php

namespace Galahad\LaravelFauxGenerics\Support;

use Galahad\LaravelFauxGenerics\Commands\MakeGenericsHelperFile;
use Illuminate\Support\ServiceProvider;

class FauxGenericsServiceProvider extends ServiceProvider
{
	public function boot() : void
	{
		if ($this->app->runningInConsole()) {
			$this->commands([
				MakeGenericsHelperFile::class,
			]);
		}
	}
}
