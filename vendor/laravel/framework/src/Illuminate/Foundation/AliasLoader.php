<?php namespace Illuminate\Foundation;

class AliasLoader {

	/**
	 * The array of class aliases.
	 *
	 * @var array = ['类别名'=>'原类名', '类别名N'=>'原类名N']
	 */
	protected $aliases;

	/**
	 * Indicates if a loader has been registered.
	 *
	 * @var bool
	 */
	protected $registered = false;

	/**
	 * The singleton instance of the loader.
	 *
	 * @var \Illuminate\Foundation\AliasLoader
	 */
	protected static $instance;

	/**
	 * Create a new AliasLoader instance.
	 *
	 * @param  array  $aliases
	 */
	private function __construct($aliases)
	{
		$this->aliases = $aliases;
	}

	/**
	 * Get or create the singleton alias loader instance.
	 *
	 * @param  array  $aliases
	 * @return \Illuminate\Foundation\AliasLoader
	 */
	public static function getInstance(array $aliases = array())
	{
		if (is_null(static::$instance)) return static::$instance = new static($aliases);

		$aliases = array_merge(static::$instance->getAliases(), $aliases);

		static::$instance->setAliases($aliases);

		return static::$instance;
	}

	/**
	 * Load a class alias if it is registered.
	 * 自动加载器，使用到类时，设置类别名
	 * @param  string  $alias 类别名
	 * @return void
	 */
	public function load($alias)
	{
		if (isset($this->aliases[$alias]))
		{	//为一个类创建别名, bool class_alias(string $original原类, string $alias别名[,bool $autoload = TRUE ])
			return class_alias($this->aliases[$alias], $alias);
		}
	}

	/**
	 * Add an alias to the loader.
	 *
	 * @param  string  $class
	 * @param  string  $alias
	 * @return void
	 */
	public function alias($class, $alias)
	{
		$this->aliases[$class] = $alias;
	}

	/**
	 * Register the loader on the auto-loader stack.
	 * 注册加载器
	 * @return void
	 */
	public function register()
	{
		if ( ! $this->registered)
		{
			$this->prependToLoaderStack();

			$this->registered = true;
		}
	}

	/**
	 * Prepend the load method to the auto-loader stack.
	 *
	 * @return void
	 */
	protected function prependToLoaderStack()
	{
		spl_autoload_register(array($this, 'load'), true, true);
	}

	/**
	 * Get the registered aliases.
	 *
	 * @return array
	 */
	public function getAliases()
	{
		return $this->aliases;
	}

	/**
	 * Set the registered aliases.
	 *
	 * @param  array  $aliases
	 * @return void
	 */
	public function setAliases(array $aliases)
	{
		$this->aliases = $aliases;
	}

	/**
	 * Indicates if the loader has been registered.
	 *
	 * @return bool
	 */
	public function isRegistered()
	{
		return $this->registered;
	}

	/**
	 * Set the "registered" state of the loader.
	 *
	 * @param  bool  $value
	 * @return void
	 */
	public function setRegistered($value)
	{
		$this->registered = $value;
	}

	/**
	 * Set the value of the singleton alias loader.
	 *
	 * @param  \Illuminate\Foundation\AliasLoader  $loader
	 * @return void
	 */
	public static function setInstance($loader)
	{
		static::$instance = $loader;
	}

	/**
	 * Clone method.
	 *
	 * @return void
	 */
	private function __clone()
	{
		//
	}

}
