<?php

namespace Galahad\LaravelFauxGenerics\Support;

use Galahad\LaravelFauxGenerics\Commands\MakeGenericsHelperFile;
use Illuminate\Support\ServiceProvider;

class FauxGenericsServiceProvider extends ServiceProvider
{
	public function register()
	{
		$this->mergeConfigFrom(__DIR__.'/../../config/faux-generics.php', 'faux-generics');
	}
	
	public function boot() : void
	{
		if ($this->app->runningInConsole()) {
			$this->commands([
				MakeGenericsHelperFile::class,
			]);
		}
	}
}
