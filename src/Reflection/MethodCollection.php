<?php

namespace Galahad\LaravelFauxGenerics\Reflection;

use Barryvdh\Reflection\DocBlock\Context;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionMethod;

class MethodCollection extends Collection
{
	/**
	 * @var \ReflectionClass
	 */
	protected $reflection;
	
	/**
	 * @var \Barryvdh\Reflection\DocBlock\Context
	 */
	protected $context;
	
	/**
	 * @param \ReflectionClass $reflection
	 * @return static
	 */
	public static function reflect(ReflectionClass $reflection) : self
	{
		return static::make($reflection->getMethods())
			->map(function(ReflectionMethod $method) {
				return new Method($method);
			})
			->forReflection($reflection);
	}
	
	/**
	 * @param \ReflectionClass $reflection
	 * @param \Barryvdh\Reflection\DocBlock\Context|null $context
	 * @return static
	 */
	public function forReflection(ReflectionClass $reflection, Context $context = null) : self
	{
		$this->reflection = $reflection;
		
		$this->context = $context ?? new Context($reflection->getNamespaceName());
		
		return $this;
	}
	
	public function methodNames() : Collection
	{
		return $this->toBase()->map(function(Method $method) {
			return $method->getName();
		});
	}
	
	public function concretePublicMethods() : self
	{
		return $this->reject(function(Method $method) {
			return false === $method->isPublic()
				or $method->isAbstract()
				or $method->isConstructor()
				or 0 === strpos($method->getName(), '__');
		});
	}
	
	public function withoutInheritedMethods() : self
	{
		return $this->reject(function(Method $method) {
			$declaring_class = $method->getDeclaringClass();
			
			return $declaring_class->getNamespaceName() !== $this->reflection->getNamespaceName()
				or $declaring_class->getName() !== $this->reflection->getName();
		});
	}
	
	public function withoutStaticMethods() : self
	{
		return $this->reject(function(Method $method) {
			return $method->isStatic();
		});
	}
	
	public function withoutMutators() : self
	{
		return $this->reject(function(Method $method) {
			return preg_match('/^(get|set).*Attribute$/', $method->getName());
		});
	}
	
	/**
	 * @param callable|null $callback
	 * @return static
	 */
	public function filter(callable $callback = null) : self
	{
		return static::make(parent::filter($callback))
			->forReflection($this->reflection, $this->context);
	}
	
	/**
	 * @param bool $callback
	 * @return static
	 */
	public function reject($callback = true) : self
	{
		return static::make(parent::reject($callback))
			->forReflection($this->reflection, $this->context);
	}
	
	/**
	 * @param mixed $items
	 * @return static
	 */
	public function merge($items) : self
	{
		return static::make(parent::merge($items))
			->forReflection($this->reflection, $this->context);
	}
}
