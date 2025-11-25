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
     * @param  array<string, int>  $tagVersions  Tag versions at time of caching
     */
    public function __construct(
        protected array $tags,
        protected array $tagVersions
    ) {}

    /**
     * Check if this dependency is stale.
     *
     * Compares stored tag versions with current versions.
     * If any tag version has increased, the cache is stale.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager
     */
    public function isStale(CacheDependencyManager $manager): bool
    {
        foreach ($this->tags as $tag) {
            $currentVersion = $manager->getTagVersion($tag);
            $storedVersion = $this->tagVersions[$tag] ?? 0;

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
     * Get the tag versions.
     *
     * @return array<string, int>
     */
    public function getTagVersions(): array
    {
        return $this->tagVersions;
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
            'versions' => $this->tagVersions,
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
            'tagVersions' => $this->tagVersions,
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
        $this->tagVersions = $data['tagVersions'];
    }
}
