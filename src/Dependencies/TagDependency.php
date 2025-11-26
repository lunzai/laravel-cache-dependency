<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Dependencies;

use Lunzai\CacheDependency\CacheDependencyManager;
use Lunzai\CacheDependency\Contracts\DependencyInterface;

/**
 * Tag dependency for cache invalidation.
 *
 * Uses version counters for O(1) invalidation. When a tag is invalidated,
 * its version counter increments, making all caches with that tag stale.
 */
class TagDependency implements DependencyInterface
{
    /**
     * Create a new tag dependency.
     *
     * @param  array<string>  $tags  Tags to track
     */
    public function __construct(
        protected array $tags
    ) {}

    /**
     * Add more tags to this dependency.
     *
     * @param  array<string>  $tags  Tags to add
     */
    public function addTags(array $tags): void
    {
        $this->tags = array_unique(array_merge($this->tags, $tags));
    }

    /**
     * Capture current tag versions as baseline.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager
     * @return array<string, int> Tag versions at time of capture
     */
    public function captureBaseline(CacheDependencyManager $manager): mixed
    {
        $tagVersions = [];

        foreach ($this->tags as $tag) {
            $tagVersions[$tag] = $manager->getTagVersion($tag);
        }

        return $tagVersions;
    }

    /**
     * Check if this dependency is stale.
     *
     * Compares stored tag versions with current versions.
     * If any tag version has increased, the cache is stale.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager
     * @param  mixed  $baseline  Tag versions captured at cache time
     */
    public function isStale(CacheDependencyManager $manager, mixed $baseline = null): bool
    {
        if (! is_array($baseline)) {
            return false; // No baseline = not stale
        }

        foreach ($this->tags as $tag) {
            $currentVersion = $manager->getTagVersion($tag);
            $storedVersion = $baseline[$tag] ?? 0;

            if ($currentVersion > $storedVersion) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the tags.
     *
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Convert the dependency to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'tag',
            'tags' => $this->tags,
        ];
    }

    /**
     * Serialize the dependency.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'tags' => $this->tags,
        ];
    }

    /**
     * Unserialize the dependency.
     *
     * @param  array<string, mixed>  $data  Serialized data
     */
    public function __unserialize(array $data): void
    {
        $this->tags = $data['tags'];
    }
}
