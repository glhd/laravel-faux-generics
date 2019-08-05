<?php

namespace Galahad\LaravelFauxGenerics\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ClassNameCollection extends Collection
{
	public static function fromAutoloader() : self
	{
		$vendor_directory = base_path('vendor');
		$class_map = include base_path('vendor/composer/autoload_classmap.php');
		
		return static::make($class_map)
			->reject(function($path) use ($vendor_directory) {
				return 0 === stripos($path, $vendor_directory);
			})
			->keys();
	}
	
	public static function allFromAutoloader() : self
	{
		$class_map = include base_path('vendor/composer/autoload_classmap.php');
		
		return static::make($class_map)
			->keys();
	}
	
	public function models() : self
	{
		return $this->filter(function($class_name) {
			return is_a($class_name, Model::class, true);
		});
	}
	
	public function excludeTests() : self
	{
		return $this->reject(function($class_name) {
			return 0 === stripos($class_name, 'Test\\')
				or 0 === stripos($class_name, 'Tests\\');
		});
	}
}
