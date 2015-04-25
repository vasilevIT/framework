<?php
/**
 * Go! AOP framework
 *
 * @copyright Copyright 2012, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Go\Instrument\Transformer;

use Go\Aop\Features;
use Go\Core\AspectKernel;
use Go\Instrument\ClassLoading\CachePathResolver;

/**
 * Caching transformer that is able to take the transformed source from a cache
 */
class CachingTransformer extends BaseSourceTransformer
{
    const CACHE_FILE_NAME = '/_transformation.cache';

    /**
     * Root path of application
     *
     * @var string
     */
    protected $rootPath = '';

    /**
     * Cache directory
     *
     * @var string
     */
    protected $cachePath = '';

    /**
     * Mask of permission bits for cache files.
     * By default, permissions are affected by the umask system setting
     *
     * @var integer|null
     */
    protected $cacheFileMode;

    /**
     * @var array|callable|SourceTransformer[]
     */
    protected $transformers = array();

    /**
     * @var CachePathResolver|null
     */
    protected $cachePathResolver;

    /**
     * Cached metadata for transformation state for the concrete file
     *
     * @var array
     */
    protected $transformationFileMap = array();

    /**
     * New metadata items, that was not present in $transformationFileMap
     *
     * @var array
     */
    protected $newTransformationMap = array();

    /**
     * Class constructor
     *
     * @param AspectKernel $kernel Instance of aspect kernel
     * @param array|callable $transformers Source transformers or callable that should return transformers
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(AspectKernel $kernel, $transformers)
    {
        parent::__construct($kernel);
        $cacheDir = $this->options['cacheDir'];
        $this->cachePathResolver = $kernel->getContainer()->get('aspect.cache.path.resolver');

        if ($cacheDir) {
            if (!is_dir($cacheDir)) {
                $cacheRootDir = dirname($cacheDir);
                if (!is_writable($cacheRootDir) || !is_dir($cacheRootDir)) {
                    throw new \InvalidArgumentException(
                        "Can not create a directory {$cacheDir} for the cache.
                        Parent directory {$cacheRootDir} is not writable or not exist.");
                }
                mkdir($cacheDir, 0770);
            }
            if (!$this->kernel->hasFeature(Features::PREBUILT_CACHE) && !is_writable($cacheDir)) {
                throw new \InvalidArgumentException("Cache directory {$cacheDir} is not writable");
            }
            $this->cachePath     = $cacheDir;
            $this->cacheFileMode = (int)$this->options['cacheFileMode'];

            if (file_exists($cacheDir. self::CACHE_FILE_NAME)) {
                $this->transformationFileMap = include $cacheDir . self::CACHE_FILE_NAME;
            }
        }

        $this->rootPath     = $this->options['appDir'];
        $this->transformers = $transformers;
    }

    /**
     * This method may transform the supplied source and return a new replacement for it
     *
     * @param StreamMetaData $metadata Metadata for source
     * @return void|bool Return false if transformation should be stopped
     */
    public function transform(StreamMetaData $metadata)
    {
        // Do not create a cache
        if (!$this->cachePath) {
            return $this->processTransformers($metadata);
        }

        $originalUri  = $metadata->uri;
        $wasProcessed = false;
        $cacheUri     = $this->cachePathResolver->getCachePathForResource($originalUri);

        $lastModified  = filemtime($originalUri);
        $hasCacheState = isset($this->transformationFileMap[$originalUri]);
        $cacheModified = $hasCacheState ? $this->transformationFileMap[$originalUri]['filemtime'] : 0;

        if ($cacheModified < $lastModified || !$this->container->isFresh($cacheModified)) {
            $wasProcessed = $this->processTransformers($metadata);
            if ($wasProcessed) {
                $parentCacheDir = dirname($cacheUri);
                if (!is_dir($parentCacheDir)) {
                    mkdir($parentCacheDir, 0770, true);
                }
                file_put_contents($cacheUri, $metadata->source);
                if ($hasCacheState && $this->cacheFileMode) {
                    chmod($cacheUri, $this->cacheFileMode);
                }
            }
            $this->newTransformationMap[$originalUri] = array(
                'filemtime' => isset($_SERVER['REQUEST_TIME']) ? $_SERVER['REQUEST_TIME'] : time(),
                'processed' => $wasProcessed
            );

            return $wasProcessed;
        }

        if ($hasCacheState) {
            $wasProcessed = $this->transformationFileMap[$originalUri]['processed'];
        }
        if ($wasProcessed) {
            $metadata->source = file_get_contents($cacheUri);
        }

        return $wasProcessed;
    }

    /**
     * Automatic destructor saves all new changes into the cache
     *
     * This implementation is not thread-safe, so be care
     */
    public function __destruct()
    {
        if ($this->newTransformationMap) {
            $fullCacheMap = $this->newTransformationMap + $this->transformationFileMap;
            $cacheData    = '<?php return ' . var_export($fullCacheMap, true) . ';';
            file_put_contents($this->cachePath . self::CACHE_FILE_NAME, $cacheData);
        }
    }

    /**
     * Iterates over transformers
     *
     * @param StreamMetaData $metadata Metadata for source code
     * @return bool False, if transformation should be stopped
     */
    private function processTransformers(StreamMetaData $metadata)
    {
        if (is_callable($this->transformers)) {
            $delayedTransformers = $this->transformers;
            $this->transformers  = $delayedTransformers();
        }
        foreach ($this->transformers as $transformer) {
            $isTransformed = $transformer->transform($metadata);
            // transformer reported about termination, next transformers will be skipped
            if ($isTransformed === false) {
                return false;
            }
        }

        return true;
    }
}
