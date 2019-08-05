<?php

/** @noinspection NestedTernaryOperatorInspection */

namespace Galahad\LaravelFauxGenerics\Reflection;

use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Tag\ParamTag;
use Barryvdh\Reflection\DocBlock\Tag\ReturnTag;
use Closure;
use Galahad\LaravelFauxGenerics\Concerns\QualifiesTypes;
use Illuminate\Support\Collection;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @mixin ReflectionMethod
 */
class Method
{
	use QualifiesTypes;
	
	/**
	 * @var \ReflectionMethod
	 */
	protected $method;
	
	/**
	 * @var \Barryvdh\Reflection\DocBlock
	 */
	protected $docblock;
	
	/**
	 * @var \Illuminate\Support\Collection|\Barryvdh\Reflection\DocBlock\Tag\ParamTag[]
	 */
	protected $parameter_tags;
	
	/**
	 * @var \Illuminate\Support\Collection|ReflectionParameter{}
	 */
	protected $parameters;
	
	/**
	 * @var \Barryvdh\Reflection\DocBlock\Tag\ReturnTag
	 */
	protected $return_tag;
	
	/**
	 * @var bool
	 */
	protected $export_docblock = true;
	
	/**
	 * @var bool
	 */
	protected $include_see_tag = true;
	
	/**
	 * @var Closure
	 */
	protected $return_type_filter;
	
	/**
	 * @var string
	 */
	protected $overridden_return_type;
	
	/**
	 * @var string
	 */
	protected $overridden_method_name;
	
	/**
	 * @var Collection
	 */
	protected $added_return_types;
	
	/**
	 * @var Collection
	 */
	protected $removed_parameters;
	
	/**
	 * @var bool
	 */
	protected $force_static = false;
	
	public function __construct(ReflectionMethod $method)
	{
		$this->method = $method;
		$this->parameters = Collection::make($method->getParameters())
			->keyBy(function(ReflectionParameter $parameter) {
				return $parameter->getName();
			});
		
		$this->docblock = new DocBlock($method->getDocComment(), new Context($method->getDeclaringClass()->getNamespaceName()));
		$this->return_tag = $this->docblock->getTagsByName('return')[0] ?? null;
		$this->parameter_tags = Collection::make($this->docblock->getTagsByName('param'))
			->keyBy(function(ParamTag $tag) {
				return ltrim($tag->getVariableName(), '$');
			});
		
		$this->added_return_types = new Collection();
		$this->removed_parameters = new Collection();
	}
	
	public function returnsType(string $type) : bool
	{
		$type = $this->qualifyType($type);
		
		if ($this->hasReturnType()) {
			return is_a($this->method->getReturnType()->getName(), $type, true);
		}
		
		if ($this->return_tag instanceof ReturnTag) {
			return Collection::make(explode('|', (string) $this->return_tag->getType()))
				->map(Closure::fromCallable([$this, 'qualifyType']))
				->contains(function(string $return_type) use ($type) {
					return is_a($return_type, $type, true);
				});
		}
		
		return false;
	}
	
	public function export() : string
	{
		$exported_code = '';
		
		// DocBlock
		if ($this->export_docblock) {
			$exported_code .= $this->exportDocBlock();
		}
		
		// Method Signature
		$exported_code .= "\n".$this->exportMethodSignature().' {}';
		
		return $exported_code;
	}
	
	public function withDocBlock(bool $export_docblock = true) : self
	{
		$this->export_docblock = $export_docblock;
		
		return $this;
	}
	
	public function withoutDocBlock() : self
	{
		return $this->withDocBlock(false);
	}
	
	public function withReturnTypeFilter(Closure $filter) : self
	{
		$this->return_type_filter = $filter;
		
		return $this;
	}
	
	public function withOverriddenReturnType(string $type) : self
	{
		$this->overridden_return_type = $this->qualifyType($type);
		
		return $this;
	}
	
	public function withAddedReturnType(string $type) : self
	{
		$this->added_return_types->push($this->qualifyType($type));
		
		return $this;
	}
	
	public function removeParameter($name_or_index) : self
	{
		$this->removed_parameters->push($name_or_index);
		
		return $this;
	}
	
	public function withNewMethodName($name) : self
	{
		$this->overridden_method_name = $name;
		
		return $this;
	}
	
	public function withoutSeeTag() : self
	{
		$this->include_see_tag = false;
		
		return $this;
	}
	
	public function forceStatic() : self
	{
		$this->force_static = true;
		
		return $this;
	}
	
	protected function exportMethodSignature() : string
	{
		$name = $this->overridden_method_name ?? $this->getName();
		$parameters = $this->exportParameters();
		
		$abstract = $this->isAbstract() ? 'abstract ' : '';
		$static = $this->isStatic() || $this->force_static ? 'static ' : '';
		$visibility = $this->isPrivate() ? 'private' : $this->isProtected() ? 'protected' : 'public';
		
		$return = '';
		if (!$this->return_type_filter && !$this->overridden_return_type && $this->hasReturnType()) {
			$return_type = $this->getReturnType();
			$nullable = $return_type->allowsNull() ? '?' : '';
			$return = ' : '.$nullable.$this->qualifyType($return_type->getName());
		}
		
		return "{$abstract}{$visibility} {$static}function {$name}({$parameters}){$return}";
	}
	
	protected function exportParameters() : string
	{
		return $this->parameters
			->reject(function(ReflectionParameter $parameter, $index) {
				return $this->removed_parameters->contains($parameter->getName())
					or $this->removed_parameters->contains($index);
			})
			->map(function(ReflectionParameter $parameter) {
				$template = '';
				
				if ($parameter->isVariadic()) {
					$template .= '...';
				}
				
				$template .= "\${$parameter->getName()}";
				
				if ($parameter->isOptional() && $parameter->isDefaultValueAvailable()) {
					$template .= ' = '.$this->normalizeDefaultValue($parameter->getDefaultValue());
				}
				
				return $template;
			})
			->implode(', ');
	}
	
	protected function exportDocBlock() : string
	{
		$docblock_tags = new Collection();
		
		// Add @see tag
		if ($this->include_see_tag) {
			$see_class_name = $this->qualifyType($this->method->getDeclaringClass()->getName());
			$see_method_name = $this->method->getName();
			$docblock_tags->push("@see {$see_class_name}::{$see_method_name}");
		}
		
		// Add @param tags
		$docblock_tags = $docblock_tags->merge($this->exportParamTags());
		
		// Add @return tags
		$docblock_tags->push($this->exportReturnTag());
		
		$docblock = $docblock_tags->filter()->implode("\n * ");
		
		return "/**\n * $docblock\n */";
	}
	
	protected function exportParamTags() : Collection
	{
		return $this->parameters
			->reject(function(ReflectionParameter $parameter, $index) {
				return $this->removed_parameters->contains($parameter->getName())
					or $this->removed_parameters->contains($index);
			})
			->map(function(ReflectionParameter $parameter) {
				$name = $parameter->getName();
				
				// Get type from type hint
				$types = Collection::make($parameter->hasType() ? [$parameter->getType()] : []);
				
				// Get types from docblock
				$tag = $this->parameter_tags->get($name);
				if ($tag instanceof ParamTag && !empty($tag_type = $tag->getType())) {
					$types = $types->merge(explode('|', $tag_type));
				}
				
				// Remove empties
				$types = $types->filter();
				
				// Stringify types
				$type = 'mixed';
				if ($types->isNotEmpty()) {
					$type = $types->map(\Closure::fromCallable([$this, 'qualifyType']))->unique()->implode('|');
				}
				
				// Add default value
				$default_value = '';
				if ($parameter->isOptional() && $parameter->isDefaultValueAvailable()) {
					$default_value = ' = '.$this->normalizeDefaultValue($parameter->getDefaultValue());
				}
				
				return "@param {$type} \${$name}{$default_value}";
			});
	}
	
	protected function exportReturnTag() : string
	{
		$types = new Collection($this->added_return_types);
		
		// Get type from type hint
		if ($this->hasReturnType() && $return_type = $this->getReturnType()) {
			$types->push($return_type->getName());
			if ($return_type->allowsNull()) {
				$types->push('null');
			}
		}
		
		// Get types from docblock
		if ($this->return_tag instanceof ReturnTag && !empty($tag_type = $this->return_tag->getType())) {
			$types = $types->merge(explode('|', $tag_type));
		}
		
		$types = $types->filter();
		
		$type = $this->overridden_return_type ?? 'mixed';
		if (!$this->overridden_return_type && $types->isNotEmpty()) {
			$type = $types
				->map(function(string $type) {
					$array_suffix = '';
					if ('[]' === substr($type, -2)) {
						$array_suffix = '[]';
						$type = substr($type, 0, -2);
					}
					
					// Apply return type filter
					if (
						$this->return_type_filter instanceof Closure
						&& !empty($filtered = call_user_func($this->return_type_filter, ltrim($type, '\\'), $this))
					) {
						$type = $filtered;
					}
					
					$type = $this->qualifyType($type);
					
					return $type.$array_suffix;
				})
				->unique()
				->implode('|');
		}
		
		return "@return {$type}";
	}
	
	public function __call($name, $arguments)
	{
		return $this->method->$name(...$arguments);
	}
	
	protected function normalizeDefaultValue($default) : string
	{
		$default = var_export($default, true);
		
		// Map null
		if ('NULL' === $default) {
			return 'null';
		}
		
		// Normalize arrays
		$default = preg_replace('/\n\s*/', ' ', $default);
		$default = preg_replace('/^\s*array\s*\(\s*(.*?)\s*\)\s*$/i', '[$1]', $default);
		$default = preg_replace('/,\]$/', ']', $default);
		$default = preg_replace('/^\[0\s*=>\s*(.*)\]$/', '[$1]', $default);
		
		return $default;
	}
}
