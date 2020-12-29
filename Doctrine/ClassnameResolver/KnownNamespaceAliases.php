<?php

namespace FS\SolrBundle\Doctrine\ClassnameResolver;

use Doctrine\ODM\MongoDB\Configuration as OdmConfiguration;
use Doctrine\ORM\Configuration as OrmConfiguration;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Class collects document and entity aliases from ORM and ODM configuration
 */
class KnownNamespaceAliases
{
    const CACHE_NOT_CREATED_ERROR = 'Failed to create the cache file. Using the NullAdapter instead. This will negatively impact performance.';
    const CACHE_NOT_COMMITTED_ERROR = 'Failed to commit the cache file. This will negatively impact performance.';
    const CACHE_KEY = 'class_names';

    /**
     * @var array
     */
    private $knownNamespaceAlias = array();

    /**
     * @var array
     */
    private $entityClassnames = array();

    /**
     * @var CacheInterface
     */
    private $classCache;

    /**
     * The bundle may invoke 'addDocumentNamespaces' or 'addEntityNamespaces' multiple times for each entity manager.
     * We do not know how often. We need to know if the cache already existed.
     * If the cache already exists we'll use the contents of the cache.
     * If the cache was created this request we invoke the metadata to get all the class names and append the results
     * of each call to the newly created cache.
     *
     * @var bool
     */
    private $cacheAlreadyExisted = true;

    /**
     * @var string
     */
    private $cacheDir;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * MappingDriverDecorator constructor.
     *
     * @param KnownNamespaceAliases $internal
     * @param LoggerInterface $logger
     * @param string $cacheDir
     */
    public function __construct(LoggerInterface $logger, string $cacheDir)
    {
        $this->cacheDir = $cacheDir;
        $this->logger = $logger;
    }

    /**
     * Commit the cached class names.
     */
    public function __destruct()
    {
        if ($this->cacheAlreadyExisted === false) {
            try {
                $this->getClassCache()->commit();
            } catch(\Exception $e) {
                $this->logger->alert(strtr(self::CACHE_NOT_COMMITTED_ERROR, [
                    '{error}' => $e->getMessage()
                ]));
            }
        }
    }

    /**
     * @param OdmConfiguration $configuration
     */
    public function addDocumentNamespaces(OdmConfiguration $configuration)
    {
        $this->knownNamespaceAlias = array_merge($this->knownNamespaceAlias, $configuration->getDocumentNamespaces());
        if ($configuration->getMetadataDriverImpl()) {
            $this->processClassNames($configuration->getMetadataDriverImpl());
        }
    }

    /**
     * @param OrmConfiguration $configuration
     */
    public function addEntityNamespaces(OrmConfiguration $configuration)
    {
        $this->knownNamespaceAlias = array_merge($this->knownNamespaceAlias, $configuration->getEntityNamespaces());
        if ($configuration->getMetadataDriverImpl()) {
            $this->processClassNames($configuration->getMetadataDriverImpl());
        }
    }

    /**
     * @param MappingDriver $driver
     * @throws CacheException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function processClassNames(MappingDriver $driver): void
    {
        $cache = $this->getClassCache();
        $cacheItem = $cache->getItem(self::CACHE_KEY);

        if ($this->cacheAlreadyExisted === true && $cacheItem->isHit()) {
            $classNames = $cacheItem->get();
            if (!empty($classNames)) {
                $this->entityClassnames = $classNames;

                return;
            }

            // Fallback in case that  the cache is empty / corrupted somehow
            $this->cacheAlreadyExisted = false;
        }

        $this->entityClassnames = array_merge($this->entityClassnames, $driver->getAllClassNames());
        $cacheItem->set($this->entityClassnames);
        $cache->saveDeferred($cacheItem);
    }

    /**
     * @param string $alias
     *
     * @return bool
     */
    public function isKnownNamespaceAlias($alias)
    {
        return isset($this->knownNamespaceAlias[$alias]);
    }

    /**
     * @param string $alias
     *
     * @return string
     */
    public function getFullyQualifiedNamespace($alias)
    {
        if ($this->isKnownNamespaceAlias($alias)) {
            return $this->knownNamespaceAlias[$alias];
        }

        return '';
    }

    /**
     * @return array
     */
    public function getAllNamespaceAliases()
    {
        return array_keys($this->knownNamespaceAlias);
    }

    /**
     * @return array
     */
    public function getEntityClassnames()
    {
        return $this->entityClassnames;
    }

    /**
     * @return CacheItemPoolInterface
     *
     * @throws \Symfony\Component\Cache\Exception\CacheException
     */
    private function getClassCache(): CacheItemPoolInterface
    {
        // Adapter that writes the classnames to a PHP file in the cache directory.
        if ($this->classCache === null) {
            try {
                $this->classCache = new PhpFilesAdapter(
                    'app.class_cache',
                    0,
                    $this->cacheDir,
                    true
                );

                $this->cacheAlreadyExisted = $this->classCache->hasItem(self::CACHE_KEY);

            } catch(CacheException $e) {

                $this->logger->alert(strtr(self::CACHE_NOT_CREATED_ERROR, [
                    '{error}' => $e->getMessage()
                ]));

                $this->classCache = new NullAdapter();
                // Avoid any attempts to write contents to the case
                $this->cacheAlreadyExisted = true;
            }
        }

        return $this->classCache;
    }
}