<?php

namespace Galahad\LaravelFauxGenerics\CodeGenerators;

use Galahad\LaravelFauxGenerics\Reflection\Method;
use Galahad\LaravelFauxGenerics\Reflection\MethodCollection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseBuilder;
use ReflectionClass;

class ModelGenerator extends CodeGenerator
{
	/**
	 * @var \ReflectionClass
	 */
	protected $eloquent_builder_reflection;
	
	/**
	 * @var \ReflectionClass
	 */
	protected $base_builder_reflection;
	
	public function __construct(ReflectionClass $reflection)
	{
		parent::__construct($reflection);
		
		$this->eloquent_builder_reflection = new ReflectionClass(EloquentBuilder::class);
		$this->base_builder_reflection = new ReflectionClass(BaseBuilder::class);
	}
	
	public function __toString()
	{
		$model_methods = $this->indent($this->modelMethods(), 2);
		$eloquent_methods = $this->indent($this->eloquentBuilderMethods(), 2);
		
		return <<<EOF
		namespace {$this->namespace} {
			class {$this->model_base_name} {
				$eloquent_methods
				$model_methods
			}
		}
		EOF;
	}
	
	protected function modelMethods() : string
	{
		return MethodCollection::reflect($this->reflection)
			->concretePublicMethods()
			->toBase()
			->map(function(Method $method) {
				return $method
					->withoutSeeTag()
					->withReturnTypeFilter(function(string $type, Method $method) {
						if (Model::class === $type || '$this' === $type || 'static' === $type) {
							return $this->model_class;
						}
						
						if (EloquentBuilder::class === $type) {
							return $this->model_builder_class;
						}
						
						if (EloquentCollection::class === $type) {
							return $this->model_collection_class;
						}
					})
					->export();
			})
			->filter(function(string $code) {
				return false !== stripos($code, $this->model_class)
					or false !== stripos($code, $this->model_builder_class)
					or false !== stripos($code, $this->model_collection_class);
			})
			->implode("\n\n");
	}
	
	protected function eloquentBuilderMethods() : string
	{
		return MethodCollection::reflect($this->eloquent_builder_reflection)
			->concretePublicMethods()
			->reject(function(Method $method) {
				return method_exists($this->model_class, $method->getName());
			})
			->map(function(Method $method) {
				return $method
					->forceStatic()
					->withReturnTypeFilter(function(string $type, Method $method) {
						if (Model::class === $type) {
							return $this->model_class;
						}
						
						if (EloquentBuilder::class === $type || '$this' === $type || 'static' === $type) {
							return $this->model_builder_class;
						}
						
						if (EloquentCollection::class === $type) {
							return $this->model_collection_class;
						}
					})
					->export();
			})
			->implode("\n\n");
	}
}
