<?php

namespace Galahad\LaravelFauxGenerics\Commands;

use Galahad\LaravelFauxGenerics\CodeGenerators\BuilderGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\CodeGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\CollectionGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\CollectionProxyGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\ModelGenerator;
use Galahad\LaravelFauxGenerics\Support\ClassNameCollection;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;

class MakeGenericsHelperFile extends Command
{
	protected $signature = 'generics-helper-file';
	
	public function handle(Filesystem $fs) : void
	{
		// TODO:
		// - Factories
		// - Paginator
		// - LengthAwarePaginator
		// - Relation ?
		// - Macros
		
		if (!$fs->exists(base_path('generics'))) {
			$fs->makeDirectory(base_path('generics'), 0755, true);
		}
		
		$fs->cleanDirectory(base_path('generics'));
		
		$progress = $this->output->createProgressBar();
		$progress->setFormat('%bar% %current%/%max% (approx. %estimated%) %message%');
		$progress->setMessage('');
		
		$generators = Collection::make([
			BuilderGenerator::class,
			CollectionProxyGenerator::class,
			CollectionGenerator::class,
			ModelGenerator::class,
		]);
		
		ClassNameCollection::fromAutoloader()
			->models()
			->excludeTests()
			->unique()
			->map(function($class_name) {
				return new ReflectionClass($class_name);
			})
			->reject(function(ReflectionClass $reflection_class) {
				return $reflection_class->isAbstract();
			})
			->tap(function(Collection $collection) use ($generators, $progress) {
				$count = $collection->count();
				$models_pluralized = Str::plural('Model', $count);
				
				$this->output->title("Processing $count $models_pluralized");
				$progress->start($generators->count() * $count);
			})
			->each(function(ReflectionClass $reflection) use ($fs, $progress, $generators) {
				$class_name = $reflection->getName();
				$class_label = class_basename($class_name);
				
				$path = base_path('generics/'.str_replace('\\', '', $class_name).'Generics.php');
				
				$progress->setMessage($class_label);
				
				// Empty file
				$fs->put($path, "<?php /** @noinspection ALL */\n\n");
				
				try {
					$generators
						->map(function($class_name) use ($reflection) {
							return new $class_name($reflection);
						})
						->each(function(CodeGenerator $generator) use ($fs, $progress, $path, $class_label) {
							$progress->setMessage($class_label.': '.class_basename($generator));
							$fs->append($path, "$generator\n\n");
							$progress->advance();
						});
				} finally {
					$progress->setMessage("$class_label: Done");
				}
			});
		
		$progress->setMessage('');
		$progress->finish();
		
		$this->info("Done\n");
	}
}
