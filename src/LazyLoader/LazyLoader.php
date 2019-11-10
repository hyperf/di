<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Di\LazyLoader;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Utils\Coroutine\Locker as CoLocker;
use Hyperf\Di\LazyLoader\PublicMethodVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

class LazyLoader {
    public const CONFIG_FILE_NAME = 'lazy_loader';
    /**
     * Indicates if a loader has been registered.
     *
     * @var bool
     */
    protected $registered = false;
    /**
     * The singleton instance of the loader.
     *
     * @var \Hyperf\Di\LazyLoader\LazyLoader
     */
    protected static $instance;

    /**
     * The Configuration object
     *
     * @var ConfigInterface
     */
    protected $config;

    private function __construct(ConfigInterface $config)
    {
    	$this->config = $config->get(self::CONFIG_FILE_NAME, []);
    	$this->register();
    }
    /**
     * Get or create the singleton lazy loader instance.
     *
     * @return \Hyperf\Di\LazyLoader\LazyLoader
     */
    public static function bootstrap(ConfigInterface $config): LazyLoader
    {
        if (is_null(static::$instance)) {
            static::$instance = new static($config);
        }
        return static::$instance;
    }
    /**
     * Load a class proxy if it is registered.
     *
     * @return null|bool
     */
    public function load(string $proxy)
    {
        if (array_key_exists($proxy, $this->config)) {
            $this->loadProxy($proxy);
            return true;
        }
    }
    /**
     * Register the loader on the auto-loader stack.
     */
    protected function register(): void
    {
        if (! $this->registered) {
            $this->prependToLoaderStack();
            $this->registered = true;
        }
    }
    /**
     * Load a real-time facade for the given proxy.
     */
    protected function loadProxy(string $proxy)
    {
        require_once $this->ensureProxyExists($proxy);
    }
    /**
     * Ensure that the given proxy has an existing real-time facade class.
     */
    protected function ensureProxyExists(string $proxy): string
    {   
        $dir = BASE_PATH . '/runtime/container/proxy/';
        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = str_replace('\\', '_', $dir . $proxy . '.lazy.php');
        $key = md5($path);
        // If the proxy file does not exist, then try to acquire the coroutine lock.
        if (! file_exists($path) && CoLocker::lock($key)) {
            $targetPath = $path . '.' . uniqid();
            $code = $this->generatorLazyProxy(
            	$proxy,
                $this->config[$proxy]
            );
            file_put_contents($targetPath, $code);
            rename($targetPath, $path);
            CoLocker::unlock($key);
        }
        return $path;
    }
    /**
     * Format the lazy proxy with the proper namespace and class.
     *
     * @return string
     */
    protected function generatorLazyProxy(string $proxy, string $target): string
    {
    	$targetReflection = new \ReflectionClass($target);
    	$fileName = $targetReflection->getFileName();
    	if (!$fileName){
        	$code = ''; // Classes and Interfaces from PHP internals
    	} else {
            $code = file_get_contents($fileName);
        }
        if ($this->isUnsupportedReflectionType($targetReflection)){
            $builder = new FallbackLazyProxyBuilder();
            return $this->buildNewCode($builder, $code, $proxy, $target);
        }
    	if ($targetReflection->isInterface()){
    		$builder = new InterfaceLazyProxyBuilder();
    		return $this->buildNewCode($builder, $code, $proxy, $target);
    	}
        $builder = new ClassLazyProxyBuilder();
        return $this->buildNewCode($builder, $code, $proxy, $target);
    }

    /**
     * These conditions are really hard to proxy via inheritence. 
     * Luckily these conditions are very rarely met.
     * 
     * TODO: implement some of them.
     * 
     * @param  \ReflectionClass $targetReflection [description]
     * @return boolean                            [description]
     */
    private function isUnsupportedReflectionType(\ReflectionClass $targetReflection): bool {
        //Final class
        if ($targetReflection->isFinal()){
            return true;
        }
        // Internal Interface
        if ($targetReflection->isInterface() && $targetReflection->isInternal()){
            return true;
        }
        // Nested Interface
        if ($targetReflection->isInterface() && !empty($targetReflection->getInterfaces())){
            return true;
        }
        // Nested AbstractClass
        if ($targetReflection->isAbstract() 
            && $targetReflection->getParentClass()
            && $targetReflection->getParentClass()->isAbstract()
        ){
            return true;
        }
        return false;
    }

    private function buildNewCode(AbstractLazyProxyBuilder $builder, string $code, string $proxy, string $target): string
    {
    	$builder->addClassBoilerplate($proxy, $target);
    	$builder->addClassRelationship();
    	$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
    	$ast = $parser->parse($code);
    	$traverser = new NodeTraverser();
    	$visitor = new PublicMethodVisitor();
    	$nameResolver = new NameResolver();
    	$traverser->addVisitor($nameResolver);
    	$traverser->addVisitor($visitor);
    	$ast = $traverser->traverse($ast);
    	$builder->addNodes($visitor->nodes);
    	$prettyPrinter = new \PhpParser\PrettyPrinter\Standard();
    	$stmts = [$builder->getNode()];
    	$newCode = $prettyPrinter->prettyPrintFile($stmts);
    	return $newCode;
    }
    /**
     * Prepend the load method to the auto-loader stack.
     */
    protected function prependToLoaderStack(): void
    {
        spl_autoload_register([$this, 'load'], true, true);
    }
}
