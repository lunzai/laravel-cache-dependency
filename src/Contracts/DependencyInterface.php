<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Contracts;

use Lunzai\CacheDependency\CacheDependencyManager;

/**
 * Interface for cache dependencies.
 *
 * All dependency types (tag, database, etc.) must implement this interface.
 */
interface DependencyInterface
{
    /**
     * Check if this dependency has become stale.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager instance
     * @return bool True if the dependency is stale, false otherwise
     */
    public function isStale(CacheDependencyManager $manager): bool;

    /**
     * Convert the dependency to an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
