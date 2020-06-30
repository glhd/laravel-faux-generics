<?php

namespace Galahad\LaravelFauxGenerics\CodeGenerators;

use Galahad\LaravelFauxGenerics\Reflection\Method;
use Galahad\LaravelFauxGenerics\Reflection\MethodCollection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionClass;

class CollectionGenerator extends CodeGenerator
{
	protected static $getters = [
		'find',
		'first',
		'firstWhere',
		'get',
		'last',
		'pop',
		'pull',
		'shift',
		'offsetGet',
	];
	
	/**
	 * @var \ReflectionClass
	 */
	protected $collection_reflection;
	
	public function __construct(ReflectionClass $reflection)
	{
		parent::__construct($reflection);
		
		$this->collection_reflection = new ReflectionClass(EloquentCollection::class);
	}
	
	public function __toString()
	{
		$eloquent_collection_class_name = $this->qualifyType(EloquentCollection::class);
		
		$collection_methods = $this->indent($this->collectionMethods(), 2);
		$proxy_doc_blocks = $this->indentDocBlock($this->proxies());
		
		return <<<EOF
		namespace {$this->namespace} {
			/**
			 * $proxy_doc_blocks
			 */
			class {$this->model_base_name}Collection extends $eloquent_collection_class_name {
				$collection_methods
			}
		}
		EOF;
	}
	
	public function filename() : string
	{
		$path = implode(DIRECTORY_SEPARATOR, explode('\\', $this->reflection->getNamespaceName()));
		$filename = "{$this->model_base_name}Collection.php";
		return "{$path}/{$filename}";
	}
	
	protected function proxies() : Collection
	{
		return Collection::make($this->collection_reflection->getDefaultProperties()['proxies'])
			->map(function(string $proxy) {
				return "@property-read \\{$this->namespace}\\{$this->model_base_name}CollectionProxy \${$proxy}";
			});
	}
	
	protected function collectionMethods() : string
	{
		return MethodCollection::reflect($this->collection_reflection)
			->concretePublicMethods()
			->filter(function(Method $method) {
				return $method->returnsType(Model::class)
					or $method->returnsType(EloquentCollection::class);
			})
			->map(function(Method $method) {
				// Add an extra return type if it's missing for getter-style methods
				if (in_array($method->getName(), static::$getters, true)) {
					$method->withAddedReturnType($this->model_class);
				}
				
				return $method
					->withReturnTypeFilter(function(string $type, Method $method) {
						if (Model::class === $type) {
							return $this->model_class;
						}
						
						if (EloquentCollection::class === $type) {
							return $this->model_collection_class;
						}
					})
					->exportWithParentCall();
			})
			->implode("\n\n");
	}
}
