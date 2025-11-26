<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency;

use Closure;
use Lunzai\CacheDependency\Dependencies\DbDependency;

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
     * @param  array<string>  $tags  Tags to associate with cache entries
     * @param  DbDependency|null  $dbDependency  Database dependency if any
     * @param  string|null  $connection  Database connection name
     */
    public function __construct(
        protected CacheDependencyManager $manager,
        protected array $tags = [],
        protected ?DbDependency $dbDependency = null,
        protected ?string $connection = null
    ) {}

    /**
     * Add tags to this pending dependency.
     *
     * @param  array<string>|string  $tags  Tag or tags to add
     * @return $this
     */
    public function tags(array|string $tags): self
    {
        $this->tags = array_merge($this->tags, (array) $tags);

        return $this;
    }

    /**
     * Set a database dependency for this pending operation.
     *
     * @param  string  $sql  SQL query for dependency checking
     * @param  array<mixed>  $params  Query parameters
     * @return $this
     */
    public function db(string $sql, array $params = []): self
    {
        $this->dbDependency = new DbDependency($sql, $params, $this->connection);

        return $this;
    }

    /**
     * Set the database connection for the dependency.
     *
     * @param  string  $connection  Connection name
     * @return $this
     */
    public function connection(string $connection): self
    {
        $this->connection = $connection;

        // If dbDependency already exists, update it
        if ($this->dbDependency) {
            $this->dbDependency->setConnection($connection);
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
     * Create a cache entry wrapper with current dependency metadata.
     *
     * @param  mixed  $value  Value to wrap
     */
    protected function createWrapper(mixed $value): CacheEntryWrapper
    {
        // Capture current tag versions
        $tagVersions = [];
        foreach ($this->tags as $tag) {
            $tagVersions[$tag] = $this->manager->getTagVersion($tag);
        }

        // Capture database baseline if dependency exists
        $dbBaseline = null;
        if ($this->dbDependency) {
            $dbBaseline = $this->dbDependency->getCurrentValue();
        }

        return new CacheEntryWrapper(
            $value,
            $this->tags,
            $tagVersions,
            $this->dbDependency,
            $dbBaseline
        );
    }
}
