<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Contracts;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Lunzai\CacheDependency\PendingDependency;

/**
 * Interface for the cache dependency manager.
 *
 * Defines the contract for dependency-based caching operations.
 */
interface CacheDependencyInterface
{
    /**
     * Create a pending dependency with tags.
     *
     * @param  array<string>|string  $tags  Tag or tags to associate with cache entries
     */
    public function tags(array|string $tags): PendingDependency;

    /**
     * Create a pending dependency with a database query.
     *
     * @param  string  $sql  SQL query to use for dependency checking
     * @param  array<mixed>  $params  Query parameters
     */
    public function db(string $sql, array $params = []): PendingDependency;

    /**
     * Retrieve an item from the cache.
     *
     * @param  string  $key  Cache key
     * @param  mixed  $default  Default value if cache miss
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache.
     *
     * @param  string  $key  Cache key
     * @param  mixed  $value  Value to cache
     * @param  int|null  $ttl  Time to live in seconds
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Get an item from cache, or execute callback and store result.
     *
     * @param  string  $key  Cache key
     * @param  int|null  $ttl  Time to live in seconds
     * @param  Closure  $callback  Callback to execute on cache miss
     */
    public function remember(string $key, ?int $ttl, Closure $callback): mixed;

    /**
     * Invalidate cache entries associated with the given tags.
     *
     * @param  array<string>|string  $tags  Tag or tags to invalidate
     */
    public function invalidateTags(array|string $tags): void;

    /**
     * Get the current version for a tag.
     *
     * @param  string  $tag  Tag name
     */
    public function getTagVersion(string $tag): int;

    /**
     * Determine if an item exists in the cache.
     *
     * @param  string  $key  Cache key
     */
    public function has(string $key): bool;

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key  Cache key
     */
    public function forget(string $key): bool;

    /**
     * Retrieve an item and delete it.
     *
     * @param  string  $key  Cache key
     * @param  mixed  $default  Default value if cache miss
     */
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * Retrieve multiple items from the cache.
     *
     * @param  array<string>  $keys  Cache keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array;

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool;

    /**
     * Get a cache store instance by name.
     *
     * @param  string|null  $name  Store name
     * @return $this
     */
    public function store(?string $name = null): self;

    /**
     * Get the underlying cache repository.
     */
    public function getStore(): Repository;
}
