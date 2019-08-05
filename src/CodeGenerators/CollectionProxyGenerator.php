<?php

namespace Galahad\LaravelFauxGenerics\CodeGenerators;

use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Galahad\LaravelFauxGenerics\Reflection\Method;
use Galahad\LaravelFauxGenerics\Reflection\MethodCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\HigherOrderCollectionProxy;
use Illuminate\Support\Str;
use ReflectionClass;

class CollectionProxyGenerator extends CodeGenerator
{
	/**
	 * @var string
	 */
	protected $return_types;
	
	public function __construct(ReflectionClass $reflection)
	{
		parent::__construct($reflection);
		
		$this->return_types = implode('|', [
			"\\{$this->namespace}\\{$this->model_base_name}Collection",
			$this->qualifyType(Collection::class),
			'mixed',
		]);
	}
	
	public function __toString()
	{
		$base_proxy_class_name = $this->qualifyType(HigherOrderCollectionProxy::class);
		
		$methods = $this->indent($this->proxyMethods(), 2);
		$attributes = $this->indentDocBlock($this->attributeDocBlockTags());
		
		return <<<EOF
		namespace {$this->namespace} {
			/**
			 * $attributes
			 */
			class {$this->model_base_name}CollectionProxy extends $base_proxy_class_name {
				$methods
			}
		}
		EOF;
	}
	
	protected function proxyMethods() : string
	{
		return MethodCollection::reflect($this->reflection)
			->withoutInheritedMethods()
			->withoutMutators()
			->concretePublicMethods()
			->map(function(Method $method) {
				return $method
					->withOverriddenReturnType($this->return_types)
					->export();
			})
			->implode("\n\n");
	}
	
	protected function attributeDocBlockTags() : Collection
	{
		return $this->mutatorsDocBlockTags()
			->merge($this->relationsDocBlockTags())
			->merge($this->castsDocBlockTags())
			->merge($this->datesDocBlockTags())
			->unique()
			->map(function($attribute) {
				return "@property-read {$this->return_types} \${$attribute}";
			});
	}
	
	protected function castsDocBlockTags() : Collection
	{
		$reflection_property = $this->reflection->getProperty('casts');
		$reflection_property->setAccessible(true);
		
		return Collection::make($reflection_property->getValue($this->model))
			->keys()
			->map(function($key) {
				return Str::snake($key);
			});
	}
	
	protected function datesDocBlockTags() : Collection
	{
		$reflection_property = $this->reflection->getProperty('dates');
		$reflection_property->setAccessible(true);
		
		return Collection::make($reflection_property->getValue($this->model))
			->map(function($key) {
				return Str::snake($key);
			});
	}
	
	protected function mutatorsDocBlockTags() : Collection
	{
		return MethodCollection::reflect($this->reflection)
			->toBase()
			->map(function(Method $method) {
				if (!preg_match('/^(?:get|set)(.*)Attribute$/', $method->getName(), $matches)) {
					return null;
				}
				
				return Str::snake($matches[1]);
			})
			->filter();
	}
	
	protected function relationsDocBlockTags() : Collection
	{
		return MethodCollection::reflect($this->reflection)
			->reject(function(Method $method) {
				return $method->getDeclaringClass()->getName() === Model::class;
			})
			->toBase()
			->filter(function(Method $method) {
				return $method->returnsType(Relation::class);
			})
			->map(function(Method $method) {
				return Str::snake($method->getName());
			})
			->filter();
	}
}
