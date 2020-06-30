<?php

namespace Galahad\LaravelFauxGenerics\Commands;

use Galahad\LaravelFauxGenerics\CodeGenerators\BuilderGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\CodeGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\CollectionGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\CollectionProxyGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\MacrosGenerator;
use Galahad\LaravelFauxGenerics\CodeGenerators\ModelGenerator;
use Galahad\LaravelFauxGenerics\Support\ClassNameCollection;
use Illuminate\Config\Repository;
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
	
	/**
	 * @var string
	 */
	protected $path;
	
	public function __construct(Filesystem $fs, Repository $config)
	{
		parent::__construct();
		
		$this->fs = $fs;
		$this->path = $config->get('faux-generics.path', base_path('.generics'));
	}
	
	public function handle() : void
	{
		$this->writeGenerics();
		$this->writeMacros();
		
		// FIXME: I think this can go away
		// $this->writeMetadata();
		
		$this->info("\nDone\n\n");
	}
	
	protected function writeGenerics() : self
	{
		if (!$this->fs->isDirectory($this->path)) {
			$this->fs->makeDirectory($this->path, 0755, true);
		}
		
		$this->fs->cleanDirectory($this->path);
		
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
				
				$progress->setMessage($class_label);
				
				try {
					$generators
						->map(function($class_name) use ($reflection) {
							return new $class_name($reflection);
						})
						->each(function(CodeGenerator $generator) use ($progress, $class_label) {
							$progress->setMessage($class_label.': '.class_basename($generator));
							$path = $this->path.DIRECTORY_SEPARATOR.$generator->filename();
							
							$directory = dirname($path);
							if (!$this->fs->isDirectory($directory)) {
								$this->fs->makeDirectory($directory, 0755, true);
							}
							
							$this->fs->put($path, "<?php\n\n{$generator}\n");
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
		$this->fs->put($this->path.'/_macros.php', "<?php /** @noinspection ALL */\n\n{$generator}");
		
		$this->info("Wrote macros file.\n");
		
		return $this;
	}
}
