<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency;

use Lunzai\CacheDependency\Contracts\DependencyInterface;

/**
 * Wraps cached data with dependency metadata.
 *
 * This class encapsulates the actual cached value along with its dependencies
 * to enable automatic staleness detection. Dependencies are stored polymorphically,
 * allowing extensibility without modifying this class.
 */
class CacheEntryWrapper
{
    /**
     * Create a new cache entry wrapper.
     *
     * @param  mixed  $data  The actual cached data
     * @param  array<array{dependency: DependencyInterface, baseline: mixed}>  $dependencies  Dependencies with baselines
     */
    public function __construct(
        protected mixed $data,
        protected array $dependencies = []
    ) {}

    /**
     * Get the wrapped data.
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Get all dependencies with their baselines.
     *
     * @return array<array{dependency: DependencyInterface, baseline: mixed}>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Check if this cache entry is stale.
     *
     * An entry is stale if ANY dependency reports being stale.
     * Iterates through all dependencies polymorphically.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager
     * @return bool True if stale, false otherwise
     */
    public function isStale(CacheDependencyManager $manager): bool
    {
        foreach ($this->dependencies as $item) {
            $dependency = $item['dependency'];
            $baseline = $item['baseline'];

            try {
                if ($dependency->isStale($manager, $baseline)) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Handle based on fail_open config
                // Global setting overrides dependency-specific settings
                $failOpen = $this->shouldFailOpen($dependency);

                if (! $failOpen) {
                    // Fail closed: treat as stale (cache miss)
                    return true;
                }

                // Fail open: log and continue checking other dependencies
                if (config('cache-dependency.log_failures', true)) {
                    logger()->warning('Dependency staleness check failed', [
                        'dependency' => get_class($dependency),
                        'error' => $e->getMessage(),
                    ]);
                }
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
            'dependencies' => $this->dependencies,
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
        $this->dependencies = $data['dependencies'] ?? [];
    }

    /**
     * Determine if failures should fail open for a given dependency.
     *
     * Checks global fail_open setting first, then dependency-specific settings.
     *
     * @param  DependencyInterface  $dependency  The dependency that failed
     * @return bool True to fail open (return cached value), false to fail closed (cache miss)
     */
    protected function shouldFailOpen(DependencyInterface $dependency): bool
    {
        // Check global fail_open setting first
        $globalFailOpen = config('cache-dependency.fail_open');

        if ($globalFailOpen !== null) {
            return (bool) $globalFailOpen;
        }

        // Fall back to dependency-specific settings
        // Currently only DB dependencies have specific fail_open config
        if ($dependency instanceof \Lunzai\CacheDependency\Dependencies\DbDependency) {
            return (bool) config('cache-dependency.db.fail_open', false);
        }

        // Default: fail closed (safer)
        return false;
    }
}
