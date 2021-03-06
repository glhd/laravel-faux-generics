<?php

namespace Galahad\LaravelFauxGenerics\CodeGenerators;

use Galahad\LaravelFauxGenerics\Reflection\Method;
use Galahad\LaravelFauxGenerics\Reflection\MethodCollection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Str;
use ReflectionClass;
use SplFileObject;

class ModelGenerator extends CodeGenerator
{
	protected static $relationship_heuristics = [
		'return $this->hasOne(' => HasOne::class,
		'return $this->hasOneThrough(' => HasOneThrough::class,
		'return $this->morphOne(' => MorphOne::class,
		'return $this->belongsTo(' => BelongsTo::class,
		'return $this->morphTo(' => MorphTo::class,
		'return $this->hasMany(' => HasMany::class,
		'return $this->hasManyThrough(' => HasManyThrough::class,
		'return $this->morphMany(' => MorphMany::class,
		'return $this->belongsToMany(' => BelongsToMany::class,
		'return $this->morphToMany(' => MorphMany::class,
		'return $this->morphedByMany(' => MorphToMany::class,
	];
	
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
		$model_methods = $this->indent($this->modelMethods(), 1);
		$eloquent_methods = $this->indent($this->eloquentBuilderMethods(), 1);
		$relationship_methods = $this->indent($this->relationshipMethods(), 1);
		
		$parent = $this->reflection->getParentClass()
			? $this->reflection->getParentClass()->getName()
			: Model::class;
		
		$parent_basename = class_basename($parent);
		
		return <<<EOF
		namespace {$this->namespace};
		
		use $parent;
		
		class {$this->model_base_name} extends $parent_basename {
			$relationship_methods
			$model_methods
			$eloquent_methods
		}
		
		EOF;
	}
	
	public function filename() : string
	{
		$path = implode(DIRECTORY_SEPARATOR, explode('\\', $this->reflection->getNamespaceName()));
		$filename = "{$this->model_base_name}.php";
		return "{$path}/{$filename}";
	}
	
	protected function relationshipMethods() : string
	{
		return MethodCollection::reflect($this->reflection)
			->concretePublicMethods()
			->withoutInheritedMethods()
			->toBase()
			->map(function(Method $method) {
				$body = $method->getBody();
				foreach (static::$relationship_heuristics as $code_fragment => $class_name) {
					$fragment_position = stripos($body, $code_fragment);
					if (false !== $fragment_position) {
						$start = $fragment_position + strlen($code_fragment);
						$end = strpos($body, ',', $start);
						
						if (false !== $end) {
							$related = Str::before(substr($body, $start, $end - $start), '::class');
							$fully_qualified = $method->qualifyClassName($related);
							
							$related_builder = $this->qualifyType("{$fully_qualified}Builder");
							$return_type = Relation::class."|$related_builder";
							
							return $method
								->withoutSeeTag()
								->withOverriddenReturnType($return_type)
								->exportWithCopiedBody();
						}
					}
				}
			})
			->filter()
			->implode("\n\n");
	}
	
	protected function modelMethods() : string
	{
		return MethodCollection::reflect($this->reflection)
			->concretePublicMethods()
			->toBase()
			->filter(function(Method $method) {
				return $method->returnsType(Model::class)
					or $method->returnsType(EloquentBuilder::class)
					or $method->returnsType(EloquentCollection::class);
			})
			->map(function(Method $method) {
				return $method
					// ->withoutSeeTag()
					->withReturnTypeFilter(function(string $type, Method $method) {
						if (Model::class === $type) {
							return $this->model_class;
						}
						
						if (EloquentBuilder::class === $type) {
							return $this->model_builder_class;
						}
						
						if (EloquentCollection::class === $type) {
							return $this->model_collection_class;
						}
					})
					->exportWithParentCall();
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
					->export(function(Method $method) {
						$name = $method->getName();
						$params = $method->exportParameters(true);
						return "return (new static())->newQuery()->{$name}({$params});";
					});
			})
			->implode("\n\n");
	}
}
