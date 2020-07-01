<?php

/** @noinspection NestedTernaryOperatorInspection */

namespace Galahad\LaravelFauxGenerics\Reflection;

use Barryvdh\Reflection\DocBlock;
use Barryvdh\Reflection\DocBlock\Context;
use Barryvdh\Reflection\DocBlock\Tag\ParamTag;
use Barryvdh\Reflection\DocBlock\Tag\ReturnTag;
use Closure;
use Galahad\LaravelFauxGenerics\Concerns\NormalizesDefaultReturnType;
use Galahad\LaravelFauxGenerics\Concerns\QualifiesTypes;
use Illuminate\Support\Collection;
use ReflectionMethod;
use ReflectionParameter;
use SplFileObject;

/**
 * @mixin ReflectionMethod
 */
class Method
{
	use QualifiesTypes, NormalizesDefaultReturnType;
	
	protected ReflectionMethod $method;
	
	protected ?DocBlock $docblock;
	
	/**
	 * @var \Illuminate\Support\Collection|\Barryvdh\Reflection\DocBlock\Tag\ParamTag[]
	 */
	protected $parameter_tags;
	
	/**
	 * @var \Illuminate\Support\Collection|ReflectionParameter[]
	 */
	protected $parameters;
	
	protected ?ReturnTag $return_tag;
	
	protected bool $export_docblock = true;
	
	protected bool $include_see_tag = true;
	
	protected ?Closure $return_type_filter = null;
	
	protected ?string $overridden_return_type = null;
	
	protected ?string $overridden_method_name = null;
	
	protected Collection $added_return_types;
	
	protected Collection $removed_parameters;
	
	protected ?string $method_body = null;
	
	protected ?string $use_statements = null;
	
	protected bool $force_static = false;
	
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
	
	public function export(Closure $body_generator = null) : string
	{
		$exported_code = '';
		
		$body = $body_generator
			? $body_generator($this)
			: '';
		
		// DocBlock
		if ($this->export_docblock) {
			$exported_code .= $this->exportDocBlock();
		}
		
		// Method Signature
		$exported_code .= "\n".$this->exportMethodSignature()." {\n{$body}\n}";
		
		return $exported_code;
	}
	
	public function exportWithParentCall(): string 
	{
		return $this->export(function(self $method) {
			$name = $method->getName();
			$params = $method->exportParameters(true);
			return "return parent::{$name}({$params});";
		});
	}
	
	public function exportWithCopiedBody(): string
	{
		return $this->export(function(self $method) {
			return $method->getBody();
		});
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
	
	public function getBody(): ?string 
	{
		if (null !== $this->method_body) {
			return $this->method_body;
		}
		
		$filename = $this->getFileName();
		$start = $this->getStartLine();
		$end = $this->getEndLine();
		
		if (!$filename || false === $start ||  false === $end) {
			return null;
		}
		
		$this->method_body = '';
		$file = new SplFileObject($filename);
		
		for ($i = $start; $i < $end - 1; $i++) {
			$file->seek($i);
			$this->method_body .= $file->current();
		}
		
		// Remove the opening and closing brackets from the method body
		$this->method_body = preg_replace('/(^\s*{|}\s*$)/', '', $this->method_body);
		
		return $this->method_body;
	}
	
	public function qualifyClassName(string $class_name): string 
	{
		if (null === $this->use_statements) {
			$this->use_statements = '';
			
			$filename = $this->getFileName();
			$start = $this->getStartLine();
			$end = $this->getEndLine();
			
			if ($filename && false !== $start && false !== $end) {
				$file = new SplFileObject($filename);
				$file->seek(0);
				do {
					$line = $file->current();
					$this->use_statements .= $line;
					$file->next();
				} while (
					false === $file->eof()
					&& !preg_match('/^\s*class /', $line)
				);
			}
		}
		
		$escaped = preg_quote($class_name, '/');
		$regex = '/^use\s+(?P<full>([A-Za-z0-9_\\\\]+\\\\)?'.$escaped.'|(?P<aliased>[A-Za-z0-9_\\\\]+)\s+as\s+'.$escaped.');$/m';
		preg_match($regex, $this->use_statements, $matches);
		
		if ($matches && isset($matches['full'])) {
			return $matches['aliased'] ?? $matches['full'];
		}
		
		return $class_name;
	}
	
	protected function exportMethodSignature() : string
	{
		$name = $this->overridden_method_name ?? $this->getName();
		$parameters = $this->exportParameters();
		
		$abstract = $this->isAbstract() ? 'abstract ' : '';
		$static = $this->isStatic() || $this->force_static ? 'static ' : '';
		$visibility = $this->isPrivate() 
			? 'private' 
			: ($this->isProtected() 
				? 'protected' 
				: 'public');
		
		$return = '';
		if (!$this->return_type_filter && !$this->overridden_return_type && $this->hasReturnType()) {
			$return_type = $this->getReturnType();
			$nullable = $return_type->allowsNull() ? '?' : '';
			$return = ' : '.$nullable.$this->qualifyType($return_type->getName());
		}
		
		return "{$abstract}{$visibility} {$static}function {$name}({$parameters}){$return}";
	}
	
	public function exportParameters($for_call_expression = false) : string
	{
		return $this->parameters
			->reject(function(ReflectionParameter $parameter, $index) {
				return $this->removed_parameters->contains($parameter->getName())
					or $this->removed_parameters->contains($index);
			})
			->map(function(ReflectionParameter $parameter) use($for_call_expression) {
				$template = '';
				
				if (false === $for_call_expression && $parameter->isVariadic()) {
					$template .= '...';
				}
				
				$template .= "\${$parameter->getName()}";
				
				if (
					false === $for_call_expression 
					&& $parameter->isOptional() 
					&& $parameter->isDefaultValueAvailable()
				) {
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
}
