<?php

namespace Galahad\LaravelFauxGenerics\Commands;

use Galahad\LaravelFauxGenerics\CodeGenerators\BuilderGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\CodeGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\CollectionGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\CollectionProxyGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\FactoryGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\MacrosGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\ModelGenerator;
use Galahad\LaravelFauxGenerics\Support\ClassNameCollection;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;

class MakeGenericsHelperFile extends Command
{
	protected $signature = 'generics-helper-file {--only=}';
	
	/**
	 * @var \Illuminate\Filesystem\Filesystem
	 */
	protected $fs;
	
	public function __construct(Filesystem $fs)
	{
		parent::__construct();
		
		$this->fs = $fs;
	}
	
	public function handle() : void
	{
		$this->writeGenerics();
		$this->writeMacros();
		$this->writeMetadata();
		
		$this->info("\nDone\n\n");
	}
	
	protected function writeGenerics() : self
	{
		if (!$this->fs->exists(base_path('generics'))) {
			$this->fs->makeDirectory(base_path('generics'), 0755, true);
		}
		
		$this->fs->cleanDirectory(base_path('generics'));
		
		$progress = $this->output->createProgressBar();
		$progress->setFormat('%bar% %current%/%max% (approx. %estimated%) %message%');
		$progress->setMessage('');
		
		$generators = Collection::make([
			BuilderGenerator::class,
			CollectionProxyGenerator::class,
			CollectionGenerator::class,
			FactoryGenerator::class,
			ModelGenerator::class,
		]);
		
		ClassNameCollection::fromAutoloader()
			->models()
			->excludeTests()
			->unique()
			->reject(function($class_name) {
				return ($only = $this->option('only'))
					&& $only !== $class_name;
			})
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
			->each(function(ReflectionClass $reflection) use ($progress, $generators) {
				$class_name = $reflection->getName();
				$class_label = class_basename($class_name);
				
				$path = base_path('generics/'.str_replace('\\', '', $class_name).'Generics.php');
				
				$progress->setMessage($class_label);
				
				// Empty file
				$this->fs->put($path, "<?php /** @noinspection ALL */\n\n");
				
				try {
					$generators
						->map(function($class_name) use ($reflection) {
							return new $class_name($reflection);
						})
						->each(function(CodeGenerator $generator) use ($progress, $path, $class_label) {
							$progress->setMessage($class_label.': '.class_basename($generator));
							$this->fs->append($path, "$generator\n\n");
							$progress->advance();
						});
				} finally {
					$progress->setMessage("$class_label: Done");
				}
			});
		
		$progress->setMessage('');
		$progress->finish();
		
		return $this;
	}
	
	protected function writeMacros() : self
	{
		$generator = new MacrosGenerator();
		$this->fs->put(base_path('generics/__macros.php'), "<?php /** @noinspection ALL */\n\n{$generator}");
		
		$this->info("Wrote macros file.\n");
		
		return $this;
	}
	
	protected function writeMetadata() : self
	{
		$factories = FactoryGenerator::builtFactories();
		
		if ($factories->isEmpty()) {
			return $this;
		}
		
		$path = $this->metadataPath();
		
		$contents = $this->fs->isReadable($path)
			? $this->fs->get($path)
			: '';
		
		$start = '// START: Generics Factories';
		$end = '// END: Generics Factories';
		
		$start_pos = strpos($contents, "$start\n");
		$end_pos = strpos($contents, $end, $start_pos);
		
		if (false !== $start_pos && false !== $end_pos) {
			$contents = substr($contents, 0, $start_pos)
				.substr($contents, $end_pos + strlen($end) + 1);
		}
		
		$factory_definitions = $factories
			->map(function($builder, $model) {
				return "'{$model}' => '{$builder}',";
			})
			->implode("\n\t");
		
		$contents .= <<<EOM
		$start
		override(\\factory(0), map([
			'' => '@FactoryBuilder',
			$factory_definitions
		]));
		$end
		EOM;
		
		$this->fs->put($path, $contents);
		
		$this->info("Wrote metadata file.\n");
		
		return $this;
	}
	
	protected function metadataPath() : string
	{
		if ($this->fs->isDirectory(base_path('.phpstorm.meta.php'))) {
			return base_path('.phpstorm.meta.php/generics.php');
		}
		
		return base_path('.phpstorm.meta.php');
	}
}
