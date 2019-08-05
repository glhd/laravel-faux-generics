<?php

namespace Galahad\LaravelFauxGenerics\CodeGenerators;

use Galahad\LaravelFauxGenerics\Concerns\IndentsCode;
use Galahad\LaravelFauxGenerics\Concerns\QualifiesTypes;
use ReflectionClass;

abstract class CodeGenerator
{
	use QualifiesTypes, IndentsCode;
	
	/**
	 * @var string
	 */
	protected $model_class;
	
	/**
	 * @var \Illuminate\Database\Eloquent\Model
	 */
	protected $model;
	
	/**
	 * @var \ReflectionClass
	 */
	protected $reflection;
	
	/**
	 * @var string
	 */
	protected $model_base_name;
	
	/**
	 * @var string
	 */
	protected $namespace;
	
	/**
	 * @var string
	 */
	protected $model_builder_class;
	
	/**
	 * @var string
	 */
	protected $model_collection_class;
	
	/**
	 * @var string
	 */
	protected $model_collection_proxy_class;
	
	/**
	 * @var string
	 */
	protected $model_factory_class;
	
	public function __construct(ReflectionClass $reflection)
	{
		$model_class = $reflection->getName();
		
		$this->model_class = $model_class;
		$this->model = new $model_class();
		
		$this->reflection = new ReflectionClass($model_class);
		
		$this->model_base_name = class_basename($this->model_class);
		$this->namespace = $this->reflection->getNamespaceName();
		
		$this->model_builder_class = "{$this->namespace}\\{$this->model_base_name}Builder";
		$this->model_collection_class = "{$this->namespace}\\{$this->model_base_name}Collection";
		$this->model_collection_proxy_class = "{$this->namespace}\\{$this->model_base_name}CollectionProxy";
		$this->model_factory_class = "{$this->namespace}\\{$this->model_base_name}FactoryBuilder";
	}
	
	abstract public function __toString();
}
