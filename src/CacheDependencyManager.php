<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Lunzai\CacheDependency\Contracts\CacheDependencyInterface;
use Lunzai\CacheDependency\Dependencies\DbDependency;

/**
 * Main cache dependency manager.
 *
 * Orchestrates all dependency-based cache operations including tag invalidation,
 * database dependencies, and cache entry wrapping/unwrapping.
 */
class CacheDependencyManager implements CacheDependencyInterface
{
    /**
     * The cache store instance.
     */
    protected Repository $store;

    /**
     * The key prefix for internal cache entries.
     */
    protected string $prefix;

    /**
     * Create a new cache dependency manager.
     *
     * @param  string|null  $store  Cache store name (null for default)
     */
    public function __construct(?string $store = null)
    {
        $this->store = Cache::store($store);
        $this->prefix = config('cache-dependency.prefix', 'cdep');
    }

    /**
     * Create a pending dependency with tags.
     *
     * @param  array<string>|string  $tags  Tag or tags to associate with cache entries
     */
    public function tags(array|string $tags): PendingDependency
    {
        return new PendingDependency($this, (array) $tags, null);
    }

    /**
     * Create a pending dependency with a database query.
     *
     * @param  string  $sql  SQL query to use for dependency checking
     * @param  array<mixed>  $params  Query parameters
     */
    public function db(string $sql, array $params = []): PendingDependency
    {
        $dbDependency = new DbDependency($sql, $params);

        return new PendingDependency($this, [], $dbDependency);
    }

    /**
     * Retrieve an item from the cache.
     *
     * @param  string  $key  Cache key
     * @param  mixed  $default  Default value if cache miss
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $wrapper = $this->store->get($key);

        // If not a wrapper, return as-is (interoperability with standard cache)
        if (! $wrapper instanceof CacheEntryWrapper) {
            return $wrapper ?? $default;
        }

        // Check if wrapper is stale
        if ($wrapper->isStale($this)) {
            $this->store->forget($key);

            return $default;
        }

        return $wrapper->getData();
    }

    /**
     * Store an item in the cache (without dependencies).
     *
     * @param  string  $key  Cache key
     * @param  mixed  $value  Value to cache
     * @param  int|null  $ttl  Time to live in seconds
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->store->put($key, $value, $ttl);
    }

    /**
     * Get an item from cache, or execute callback and store result.
     *
     * @param  string  $key  Cache key
     * @param  int|null  $ttl  Time to live in seconds
     * @param  Closure  $callback  Callback to execute on cache miss
     */
    public function remember(string $key, ?int $ttl, Closure $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    /**
     * Invalidate cache entries associated with the given tags.
     *
     * This is an O(1) operation that increments the tag version counter.
     * All cached entries with these tags will automatically become stale.
     *
     * @param  array<string>|string  $tags  Tag or tags to invalidate
     */
    public function invalidateTags(array|string $tags): void
    {
        foreach ((array) $tags as $tag) {
            $versionKey = $this->getTagVersionKey($tag);

            // Try atomic increment, fallback to put if store doesn't support it
            $newVersion = $this->store->increment($versionKey);

            if ($newVersion === false) {
                // Increment failed, set to 1 with TTL
                $this->store->put($versionKey, 1, $this->getTagVersionTtl());
            }
        }
    }

    /**
     * Get the current version for a tag.
     *
     * @param  string  $tag  Tag name
     */
    public function getTagVersion(string $tag): int
    {
        return (int) $this->store->get($this->getTagVersionKey($tag), 0);
    }

    /**
     * Get the cache key for a tag version.
     *
     * @param  string  $tag  Tag name
     */
    protected function getTagVersionKey(string $tag): string
    {
        return "{$this->prefix}:tag:{$tag}";
    }

    /**
     * Get the TTL for tag version counters.
     */
    protected function getTagVersionTtl(): int
    {
        return config('cache-dependency.tag_version_ttl', 86400 * 30);
    }

    /**
     * Determine if an item exists in the cache and is not stale.
     *
     * @param  string  $key  Cache key
     */
    public function has(string $key): bool
    {
        $value = $this->get($key);

        return $value !== null;
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key  Cache key
     */
    public function forget(string $key): bool
    {
        return $this->store->forget($key);
    }

    /**
     * Retrieve an item and delete it.
     *
     * @param  string  $key  Cache key
     * @param  mixed  $default  Default value if cache miss
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key);

        $this->forget($key);

        return $value ?? $default;
    }

    /**
     * Retrieve multiple items from the cache.
     *
     * @param  array<string>  $keys  Cache keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        return $this->store->flush();
    }

    /**
     * Get a cache store instance by name.
     *
     * @param  string|null  $name  Store name
     * @return $this
     */
    public function store(?string $name = null): self
    {
        return new self($name);
    }

    /**
     * Get the underlying cache repository.
     */
    public function getStore(): Repository
    {
        return $this->store;
    }
}
