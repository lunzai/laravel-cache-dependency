<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency;

use Closure;
use Lunzai\CacheDependency\Contracts\DependencyInterface;
use Lunzai\CacheDependency\Dependencies\DbDependency;
use Lunzai\CacheDependency\Dependencies\TagDependency;

/**
 * Fluent interface for building dependency-tracked cache operations.
 *
 * This class allows chaining of tags() and db() methods before
 * performing cache operations like put(), remember(), or forever().
 */
class PendingDependency
{
    /**
     * Create a new pending dependency.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager
     * @param  array<DependencyInterface>  $dependencies  Array of dependency instances
     */
    public function __construct(
        protected CacheDependencyManager $manager,
        protected array $dependencies = []
    ) {}

    /**
     * Add tags to this pending dependency.
     *
     * @param  array<string>|string  $tags  Tag or tags to add
     * @return $this
     */
    public function tags(array|string $tags): self
    {
        // Check if we already have a TagDependency
        $tagDependency = $this->findDependency(TagDependency::class);

        if ($tagDependency) {
            // Merge with existing tags
            $tagDependency->addTags((array) $tags);
        } else {
            // Create new TagDependency
            $this->dependencies[] = new TagDependency((array) $tags);
        }

        return $this;
    }

    /**
     * Set a database dependency for this pending operation.
     *
     * @param  string  $sql  SQL query for dependency checking
     * @param  array<mixed>  $params  Query parameters
     * @param  string|null  $connection  Database connection name
     * @return $this
     */
    public function db(string $sql, array $params = [], ?string $connection = null): self
    {
        $this->dependencies[] = new DbDependency($sql, $params, $connection);

        return $this;
    }

    /**
     * Set the database connection for the dependency.
     *
     * @deprecated Use db($sql, $params, $connection) instead
     *
     * @param  string  $connection  Connection name
     * @return $this
     */
    public function connection(string $connection): self
    {
        // Find the last DbDependency and update its connection
        for ($i = count($this->dependencies) - 1; $i >= 0; $i--) {
            if ($this->dependencies[$i] instanceof DbDependency) {
                $this->dependencies[$i]->setConnection($connection);
                break;
            }
        }

        return $this;
    }

    /**
     * Store an item in the cache with dependencies.
     *
     * @param  string  $key  Cache key
     * @param  mixed  $value  Value to cache
     * @param  int|null  $ttl  Time to live in seconds
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $wrapper = $this->createWrapper($value);

        return $this->manager->getStore()->put($key, $wrapper, $ttl);
    }

    /**
     * Get an item from cache, or execute callback and store result with dependencies.
     *
     * @param  string  $key  Cache key
     * @param  int|null  $ttl  Time to live in seconds
     * @param  Closure  $callback  Callback to execute on cache miss
     */
    public function remember(string $key, ?int $ttl, Closure $callback): mixed
    {
        $value = $this->manager->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    /**
     * Store an item in the cache indefinitely with dependencies.
     *
     * @param  string  $key  Cache key
     * @param  mixed  $value  Value to cache
     */
    public function forever(string $key, mixed $value): bool
    {
        $wrapper = $this->createWrapper($value);

        return $this->manager->getStore()->forever($key, $wrapper);
    }

    /**
     * Store multiple items in the cache with dependencies.
     *
     * @param  array<string, mixed>  $values  Key-value pairs to cache
     * @param  int|null  $ttl  Time to live in seconds
     */
    public function putMany(array $values, ?int $ttl = null): bool
    {
        $wrappedValues = [];

        foreach ($values as $key => $value) {
            $wrappedValues[$key] = $this->createWrapper($value);
        }

        return $this->manager->getStore()->putMany($wrappedValues, $ttl);
    }

    /**
     * Create a cache entry wrapper with current dependency baselines.
     *
     * @param  mixed  $value  Value to wrap
     */
    protected function createWrapper(mixed $value): CacheEntryWrapper
    {
        $dependenciesWithBaselines = [];

        foreach ($this->dependencies as $dependency) {
            try {
                $baseline = $dependency->captureBaseline($this->manager);

                $dependenciesWithBaselines[] = [
                    'dependency' => $dependency,
                    'baseline' => $baseline,
                ];
            } catch (\Throwable $e) {
                // Handle baseline capture failure
                $allowFailure = config('cache-dependency.allow_baseline_failure', false);

                if (! $allowFailure) {
                    throw $e;
                }

                // Log and skip this dependency
                if (config('cache-dependency.log_failures', true)) {
                    logger()->warning('Failed to capture dependency baseline', [
                        'dependency' => get_class($dependency),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return new CacheEntryWrapper($value, $dependenciesWithBaselines);
    }

    /**
     * Find a dependency by class name.
     *
     * @param  string  $className  The class name to find
     */
    protected function findDependency(string $className): ?DependencyInterface
    {
        foreach ($this->dependencies as $dependency) {
            if ($dependency instanceof $className) {
                return $dependency;
            }
        }

        return null;
    }
}
