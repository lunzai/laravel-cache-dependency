<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Contracts;

use Lunzai\CacheDependency\CacheDependencyManager;

/**
 * Interface for cache dependencies.
 *
 * All dependency types (tag, database, etc.) must implement this interface
 * to participate in cache staleness detection.
 */
interface DependencyInterface
{
    /**
     * Capture the current baseline value for this dependency.
     *
     * This value will be stored with the cached entry and passed to
     * isStale() during staleness checks.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager instance
     * @return mixed The baseline value (can be anything serializable)
     */
    public function captureBaseline(CacheDependencyManager $manager): mixed;

    /**
     * Check if this dependency has become stale.
     *
     * Compares the current state with the baseline captured at cache time.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager instance
     * @param  mixed  $baseline  The baseline value captured at cache time
     * @return bool True if the dependency is stale, false otherwise
     */
    public function isStale(CacheDependencyManager $manager, mixed $baseline = null): bool;

    /**
     * Convert the dependency to an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
