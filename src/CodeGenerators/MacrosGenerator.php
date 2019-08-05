<?php

namespace Galahad\LaravelFauxGenerics\CodeGenerators;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Galahad\LaravelFauxGenerics\Concerns\IndentsCode;
use Galahad\LaravelFauxGenerics\Concerns\NormalizesDefaultReturnType;
use Galahad\LaravelFauxGenerics\Concerns\QualifiesTypes;
use Illuminate\Auth\RequestGuard;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Database\Eloquent\FactoryBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\PresetCommand;
use Illuminate\Foundation\Testing\TestResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailer;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Rule;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use SplFileObject;
use Throwable;

/**
 * Based heavily on tutorigo/laravel-ide-macros
 *
 * @see https://github.com/KristofMorva/laravel-ide-macros
 */
class MacrosGenerator
{
	use QualifiesTypes, IndentsCode, NormalizesDefaultReturnType;
	
	protected static $macro_classes = [
		Blueprint::class,
		Arr::class,
		IlluminateCarbon::class,
		Carbon::class,
		CarbonImmutable::class,
		CarbonInterval::class,
		CarbonPeriod::class,
		Collection::class,
		Event::class,
		FactoryBuilder::class,
		Filesystem::class,
		Mailer::class,
		PresetCommand::class,
		Redirector::class,
		Relation::class,
		Repository::class,
		ResponseFactory::class,
		Route::class,
		Router::class,
		Rule::class,
		Str::class,
		TestResponse::class,
		Translator::class,
		UrlGenerator::class,
		Builder::class,
		JsonResponse::class,
		RedirectResponse::class,
		RequestGuard::class,
		Response::class,
		Request::class,
		SessionGuard::class,
		UploadedFile::class,
	];
	
	public function __toString()
	{
		return Collection::make(static::$macro_classes)
			->map(function($class_name) {
				try {
					$reflection = new ReflectionClass($class_name);
					
					if ($macros = $this->getMacros($reflection, 'macros')) {
						return [$reflection, $macros];
					}
					
					if ($macros = $this->getMacros($reflection, 'globalMacros')) {
						return [$reflection, $macros];
					}
				} catch (Throwable $exception) {
					// Discard anything we can't instantiate
				}
				
				return null;
			})
			->filter()
			->map(function(array $tuple) {
				/** @var ReflectionClass $reflection */
				[$reflection, $macros] = $tuple;
				
				$macro_functions = Collection::make($macros)
					->map(function($macro, $name) {
						$function = is_array($macro)
							? new ReflectionMethod(is_object($macro[0]) ? get_class($macro[0]) : $macro[0], $macro[1])
							: new ReflectionFunction($macro);
						
						$parameters = $this->exportParameters(Collection::make($function->getParameters()));
						
						$body = '';
						if (($filename = $function->getFileName()) && ($start = $function->getStartLine()) && ($end = $function->getEndLine())) {
							$file = new SplFileObject($filename);
							for ($i = $start; $i < $end - 1; $i++) {
								$file->seek($i);
								$body .= $file->current();
							}
							
							$body = $this->indent(preg_replace('/\n(\s\t)+/', "\n", $body));
						}
						
						return <<<EOM
						public function {$name}($parameters) {
							$body
						}
						EOM;
					})
					->implode("\n");
				
				$macro_functions = $this->indent($macro_functions, 2);
				
				return <<<EOM
				namespace {$reflection->getNamespaceName()} {
					class {$reflection->getShortName()} {
						$macro_functions
					}
				}
				EOM;
			})
			->implode("\n\n");
	}
	
	protected function getMacros(ReflectionClass $reflection, string $property_name) : ?array
	{
		if ($reflection->hasProperty($property_name)) {
			$property = $reflection->getProperty($property_name);
			$property->setAccessible(true);
			
			if (($macros = $property->getValue()) && !empty($macros)) {
				return $macros;
			}
		}
		
		return null;
	}
	
	protected function exportParameters(Collection $parameters) : string
	{
		return $parameters
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
}
