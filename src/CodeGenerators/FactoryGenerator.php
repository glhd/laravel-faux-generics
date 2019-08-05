<?php

namespace Galahad\LaravelFauxGenerics\CodeGenerators;

use Illuminate\Database\Eloquent\Factory;
use Illuminate\Support\Collection;
use ReflectionClass;
use Throwable;

class FactoryGenerator extends CodeGenerator
{
	/**
	 * @var Collection
	 */
	protected static $factories;
	
	/**
	 * @var Collection
	 */
	protected static $built;
	
	public static function builtFactories() : Collection
	{
		return static::$built;
	}
	
	protected static function factories() : Collection
	{
		if (null === static::$factories) {
			$definitions = (new ReflectionClass(Factory::class))->getProperty('definitions');
			$definitions->setAccessible(true);
			
			static::$factories = Collection::make($definitions->getValue(app(Factory::class)))->keys();
		}
		
		return static::$factories;
	}
	
	protected static function markAsBuild($class_name, $factory_builder) : bool
	{
		if (null === static::$built) {
			static::$built = new Collection();
		}
		
		static::$built->put($class_name, $factory_builder);
		
		return true;
	}
	
	public function __toString()
	{
		// Skip if we don't have a factory for this class
		if (!static::factories()->contains($this->model_class)) {
			return "// No factories for {$this->model_class}";
		}
		
		$basename = class_basename($this->model_factory_class);
		
		static::markAsBuild($this->model_class, $this->model_factory_class);
		
		return <<<EOM
		namespace {$this->namespace} {
			class {$basename} extends \Illuminate\Database\Eloquent\FactoryBuilder {
				/**
				 * @param array \$attributes = []
				 * @return {$this->model_collection_class}|{$this->model_class}[]|{$this->model_class}
				 */
				public function create(\$attributes = []) {}
				
				/**
				 * @param array \$attributes = []
				 * @return {$this->model_collection_class}|{$this->model_class}[]|{$this->model_class}
				 */
				public function make(\$attributes = []) {}
			}
		}
		EOM;
	}
}
