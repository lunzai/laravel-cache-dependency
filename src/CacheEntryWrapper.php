<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency;

use Lunzai\CacheDependency\Dependencies\DbDependency;

/**
 * Wraps cached data with dependency metadata.
 *
 * This class encapsulates the actual cached value along with its dependencies
 * (tags and database queries) to enable automatic staleness detection.
 */
class CacheEntryWrapper
{
    /**
     * Create a new cache entry wrapper.
     *
     * @param  mixed  $data  The actual cached data
     * @param  array<string>  $tags  Tags associated with this cache entry
     * @param  array<string, int>  $tagVersions  Tag versions at time of caching
     * @param  DbDependency|null  $dbDependency  Database dependency if any
     * @param  mixed  $dbBaseline  Baseline database value at time of caching
     */
    public function __construct(
        protected mixed $data,
        protected array $tags,
        protected array $tagVersions,
        protected ?DbDependency $dbDependency,
        protected mixed $dbBaseline
    ) {}

    /**
     * Get the wrapped data.
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Get the tags associated with this cache entry.
     *
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Check if this cache entry is stale.
     *
     * An entry is stale if:
     * - Any tag version has increased since caching
     * - The database dependency query result has changed
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager
     * @return bool True if stale, false otherwise
     */
    public function isStale(CacheDependencyManager $manager): bool
    {
        // Check tag versions
        foreach ($this->tags as $tag) {
            $currentVersion = $manager->getTagVersion($tag);
            $storedVersion = $this->tagVersions[$tag] ?? 0;

            if ($currentVersion > $storedVersion) {
                return true;
            }
        }

        // Check database dependency
        if ($this->dbDependency !== null) {
            try {
                $currentValue = $this->dbDependency->getCurrentValue();

                if ($currentValue !== $this->dbBaseline) {
                    return true;
                }
            } catch (\Exception $e) {
                // Handle based on fail_open config
                $failOpen = config('cache-dependency.db.fail_open', false);

                if (! $failOpen) {
                    // Fail closed: treat as stale (cache miss)
                    return true;
                }

                // Fail open: treat as not stale (return cached value)
                return false;
            }
        }

        return false;
    }

    /**
     * Serialize the wrapper for storage.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'data' => $this->data,
            'tags' => $this->tags,
            'tagVersions' => $this->tagVersions,
            'dbDependency' => $this->dbDependency,
            'dbBaseline' => $this->dbBaseline,
        ];
    }

    /**
     * Unserialize the wrapper from storage.
     *
     * @param  array<string, mixed>  $data  Serialized data
     */
    public function __unserialize(array $data): void
    {
        $this->data = $data['data'];
        $this->tags = $data['tags'];
        $this->tagVersions = $data['tagVersions'];
        $this->dbDependency = $data['dbDependency'];
        $this->dbBaseline = $data['dbBaseline'];
    }
}
