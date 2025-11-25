<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Facades;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Facade;
use Lunzai\CacheDependency\CacheDependencyManager;
use Lunzai\CacheDependency\PendingDependency;

/**
 * Facade for the Cache Dependency Manager.
 *
 * @method static PendingDependency tags(array|string $tags)
 * @method static PendingDependency db(string $sql, array $params = [])
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool put(string $key, mixed $value, ?int $ttl = null)
 * @method static mixed remember(string $key, ?int $ttl, Closure $callback)
 * @method static void invalidateTags(array|string $tags)
 * @method static int getTagVersion(string $tag)
 * @method static bool has(string $key)
 * @method static bool forget(string $key)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static array many(array $keys)
 * @method static bool flush()
 * @method static CacheDependencyManager store(?string $name = null)
 * @method static Repository getStore()
 *
 * @see CacheDependencyManager
 */
class CacheDependency extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'cache.dependency';
    }
}
