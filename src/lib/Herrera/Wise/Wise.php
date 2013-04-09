<?php

namespace Herrera\Wise;

use Herrera\Wise\Exception\LoaderException;
use Herrera\Wise\Exception\LogicException;
use Herrera\Wise\Exception\ProcessorException;
use Herrera\Wise\Processor\ProcessorInterface;
use Herrera\Wise\Resource\ResourceAwareInterface;
use Herrera\Wise\Resource\ResourceCollectorInterface;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolver;

/**
 * Manages access to the configuration data.
 *
 * @author Kevin Herrera <kevin@herrera.io>
 */
class Wise
{
    /**
     * The cache directory path.
     *
     * @var string
     */
    private $cacheDir;

    /**
     * The resource collector.
     *
     * @var ResourceCollectorInterface
     */
    private $collector;

    /**
     * The debug mode flag.
     *
     * @var boolean
     */
    private $debug;

    /**
     * The configuration loader.
     *
     * @var LoaderInterface
     */
    private $loader;

    /**
     * The configuration processor.
     *
     * @var ProcessorInterface
     */
    private $processor;

    /**
     * Sets the debugging mode.
     *
     * @param boolean $debug Enable debugging?
     */
    public function __construct($debug = false)
    {
        $this->debug = $debug;
    }

    /**
     * Creates a pre-configured instance of Wise.
     *
     * @param array|string $paths The configuration directory path(s).
     * @param string       $cache The cache directory path.
     * @param boolean      $debug Enable debugging?
     *
     * @return Wise The instance.
     */
    public static function create($paths, $cache = null, $debug = false)
    {
        $wise = new self($debug);

        if ($cache) {
            $wise->setCacheDir($cache);
        }

        $locator = new FileLocator($paths);

        $wise->setCollector(new Resource\ResourceCollector());
        $wise->setLoader(
            new DelegatingLoader(
                new LoaderResolver(
                    array(
                        new Loader\IniFileLoader($locator),
                        new Loader\JsonFileLoader($locator),
                        new Loader\PhpFileLoader($locator),
                        new Loader\XmlFileLoader($locator),
                        new Loader\YamlFileLoader($locator)
                    )
                )
            )
        );

        return $wise;
    }

    /**
     * Returns the cache directory path.
     *
     * @return string The path.
     */
    public function getCacheDir()
    {
        return $this->cacheDir;
    }

    /**
     * Returns the resource collector.
     *
     * @return ResourceCollectorInterface The collector.
     */
    public function getCollector()
    {
        return $this->collector;
    }

    /**
     * Returns the configuration loader.
     *
     * @return LoaderInterface The loader.
     */
    public function getLoader()
    {
        return $this->loader;
    }

    /**
     * Returns the configuration processor.
     *
     * @return ProcessorInterface The processor.
     */
    public function getProcessor()
    {
        return $this->processor;
    }

    /**
     * Checks if debugging is enabled.
     *
     * @return boolean TRUE if it is enabled, FALSE if not.
     */
    public function isDebugEnabled()
    {
        return $this->debug;
    }

    /**
     * Loads the configuration data from a resource.
     *
     * @param mixed   $resource A resource.
     * @param string  $type     The resource type.
     * @param boolean $require  Require processing?
     *
     * @return array The data.
     *
     * @throws LoaderException If the loader could not be used.
     * @throws LogicException  If no loader has been configured.
     */
    public function load($resource, $type = null, $require = false)
    {
        if (null === $this->loader) {
            throw new LogicException('No loader has been configured.');
        }

        if (false === $this->loader->supports($resource, $type)) {
            throw LoaderException::format(
                'The resource "%s"%s is not supported by the loader.',
                is_scalar($resource) ? $resource : gettype($resource),
                $type ? " ($type)" : ''
            );
        }

        if ($this->cacheDir
            && $this->collector
            && is_string($resource)
            && (false === strpos("\n", $resource))
            && (false === strpos("\r", $resource))) {
            $cache = new ConfigCache(
                $this->cacheDir
                    . DIRECTORY_SEPARATOR
                    . basename($resource)
                    . '.cache',
                $this->debug
            );

            if ($cache->isFresh()) {
                return require $cache;
            }
        }

        if ($this->collector) {
            $this->collector->clearResources();
        }

        if ($this->processor && $this->processor->supports($resource, $type)) {
            $data = $this->loader->load($resource, $type);
            $data = $this->processor->process($data);
        } elseif ($require) {
            throw ProcessorException::format(
                'The resource "%s"%s is not supported by the processor.',
                is_string($resource) ? $resource : gettype($resource),
                $type ? " ($type)" : ''
            );
        } else {
            $data = $this->loader->load($resource, $type);
        }

        if (isset($cache)) {
            $cache->write(
                '<?php return ' . var_export($data, true) . ';',
                $this->collector->getResources()
            );
        }

        return $data;
    }

    /**
     * Sets the cache directory path.
     *
     * @param string $path The path.
     */
    public function setCacheDir($path)
    {
        $this->cacheDir = $path;
    }

    /**
     * Sets the resource collector.
     *
     * @param ResourceCollectorInterface $collector The collector.
     */
    public function setCollector(ResourceCollectorInterface $collector)
    {
        $this->collector = $collector;

        if ($this->loader
            && ($this->loader instanceof ResourceAwareInterface)) {
            $this->loader->setResourceCollector($collector);
        }
    }

    /**
     * Sets a configuration loader.
     *
     * @param LoaderInterface $loader A loader.
     */
    public function setLoader(LoaderInterface $loader)
    {
        $this->loader = $loader;

        if ($this->collector && ($loader instanceof ResourceAwareInterface)) {
            $loader->setResourceCollector($this->collector);
        }
    }

    /**
     * Sets a configuration processor.
     *
     * @param ProcessorInterface $processor A processor.
     */
    public function setProcessor(ProcessorInterface $processor)
    {
        $this->processor = $processor;
    }
}