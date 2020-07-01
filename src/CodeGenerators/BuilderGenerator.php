<?php

namespace Galahad\LaravelFauxGenerics\CodeGenerators;

use Closure;
use Galahad\LaravelFauxGenerics\Reflection\Method;
use Galahad\LaravelFauxGenerics\Reflection\MethodCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Foundation\Application;
use ReflectionClass;

class BuilderGenerator extends CodeGenerator
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
		
		$this->filename();
		
		$this->eloquent_builder_reflection = new ReflectionClass(EloquentBuilder::class);
		$this->base_builder_reflection = new ReflectionClass(BaseBuilder::class);
	}
	
	public function __toString()
	{
		$eloquent_builder_class_name = $this->qualifyType(EloquentBuilder::class);
		
		$scope_methods = $this->indent($this->scopeMethods(), 1);
		$class_methods = $this->indent($this->eloquentBuilderMethods(), 1);
		$pass_thru_methods = $this->indent($this->passThruBaseBuilderMethods(), 1);
		$forwarded_methods = $this->indent($this->forwardedBaseBuilderMethods(), 1);
		
		return <<<EOF
		namespace {$this->namespace};
		
		use $eloquent_builder_class_name;
		
		class {$this->model_base_name}Builder extends Builder {
			/**
			* @var \\{$this->model_class}
			*/
			protected \$model;
		
			$scope_methods
			$class_methods
			$pass_thru_methods
			$forwarded_methods
		}
		
		EOF;
	}
	
	public function filename() : string
	{
		$path = implode(DIRECTORY_SEPARATOR, explode('\\', $this->reflection->getNamespaceName()));
		$filename = "{$this->model_base_name}Builder.php";
		return "{$path}/{$filename}";
	}
	
	protected function scopeMethods() : string
	{
		return MethodCollection::reflect($this->reflection)
			->withoutStaticMethods()
			->concretePublicMethods()
			->filter(function(Method $method) {
				return 0 === stripos($method->getName(), 'scope');
			})
			->map(function(Method $method) {
				return $method
					->withNewMethodName(lcfirst(substr($method->getName(), 5)))
					->withOverriddenReturnType("$this->model_builder_class|static")
					->removeParameter(0)
					->export(function(Method $method) {
						$name = $method->getName();
						$params = $method->exportParameters(true);
						
						return <<<END_CODE
						return \$this->callScope(function(...\$parameters) {
							return \$this->model->{$name}(...\$parameters) ?? \$this;
						}, [{$params}]);
						END_CODE;
					});
			})
			->implode("\n\n");
	}
	
	protected function eloquentBuilderMethods() : string
	{
		return MethodCollection::reflect($this->eloquent_builder_reflection)
			->withoutStaticMethods()
			->concretePublicMethods()
			->filter(function(Method $method) {
				return $method->returnsType(Model::class)
					or $method->returnsType(EloquentCollection::class)
					or $method->returnsType(EloquentBuilder::class);
			})
			->map(function(Method $method) {
				return $method
					->withReturnTypeFilter(Closure::fromCallable([$this, 'returnTypeFilter']))
					->export(function(Method $method) {
						$name = $method->getName();
						$params = $method->exportParameters(true);
						return "return parent::{$name}({$params});";
					});
			})
			->implode("\n\n");
	}
	
	protected function passThruBaseBuilderMethods() : string
	{
		$passthru_methods = $this->eloquent_builder_reflection->getDefaultProperties()['passthru'];
		
		return MethodCollection::reflect($this->base_builder_reflection)
			->withoutStaticMethods()
			->filter(function(Method $method) use ($passthru_methods) {
				return in_array($method->getName(), $passthru_methods, true);
			})
			->map(function(Method $method) {
				return $method->export(function(Method $method) {
					$name = $method->getName();
					$params = $method->exportParameters(true);
					return "return \$this->toBase()->{$name}({$params});";
				});
			})
			->implode("\n\n");
	}
	
	protected function forwardedBaseBuilderMethods() : string
	{
		$passthru_methods = $this->eloquent_builder_reflection->getDefaultProperties()['passthru'];
		
		return MethodCollection::reflect($this->base_builder_reflection)
			->concretePublicMethods()
			->withoutStaticMethods()
			->reject(function(Method $method) use ($passthru_methods) {
				return in_array($method->getName(), $passthru_methods, true)
					or method_exists($this->model_class, $method->getName())
					or method_exists(EloquentBuilder::class, $method->getName());
			})
			->map(function(Method $method) {
				return $method
					->withOverriddenReturnType($this->model_builder_class)
					->export(function(Method $method) {
						$name = $method->getName();
						$params = $method->exportParameters(true);
						return "\$this->query->{$name}({$params});\nreturn \$this;";
					});
			})
			->implode("\n\n");
	}
	
	protected function returnTypeFilter(string $type, Method $method)
	{
		if (Model::class === $type) {
			return $this->model_class;
		}
		
		if (BaseBuilder::class === $type || EloquentBuilder::class === $type) {
			return $this->model_builder_class;
		}
		
		if (EloquentCollection::class === $type) {
			return $this->model_collection_class;
		}
	}
}
