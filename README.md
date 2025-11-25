# Laravel Cache Dependency

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lunzai/laravel-cache-dependency.svg?style=flat-square)](https://packagist.org/packages/lunzai/laravel-cache-dependency)
[![Tests](https://img.shields.io/github/actions/workflow/status/lunzai/laravel-cache-dependency/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/lunzai/laravel-cache-dependency/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/lunzai/laravel-cache-dependency.svg?style=flat-square)](https://packagist.org/packages/lunzai/laravel-cache-dependency)
[![License](https://img.shields.io/packagist/l/lunzai/laravel-cache-dependency.svg?style=flat-square)](https://packagist.org/packages/lunzai/laravel-cache-dependency)

A dependency-based caching system for Laravel inspired by Yii2. Features tag dependencies with O(1) invalidation and database dependencies for automatic cache freshness.

## Why This Package?

Laravel's built-in cache tags have fundamental limitations:

- **Retrieval requires exact tag match** — You must provide the exact same tags used when caching
- **Limited driver support** — Only works with Redis and Memcached
- **No database dependencies** — No automatic invalidation when data changes

This package solves all three problems.

## Features

- **Tag Dependencies:** Tags stored as metadata, retrieval without tags, O(1) invalidation via version counters
- **Database Dependencies:** Automatic cache invalidation when query results change
- **Universal Driver Support:** Works with all Laravel cache drivers (file, array, database, Redis, Memcached)
- **Cache Interoperability:** Works seamlessly with Laravel's Cache facade
- **Configurable Behavior:** Fail-open or fail-closed when database queries fail

## Installation

```bash
composer require lunzai/laravel-cache-dependency
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag="cache-dependency-config"
```

## Requirements

- PHP 8.2+
- Laravel 11+

## Quick Start

```php
use Lunzai\CacheDependency\Facades\CacheDependency;

// Cache with tags
CacheDependency::tags(['users', 'permissions'])
    ->put('user.1.permissions', $permissions, 3600);

// Retrieve WITHOUT tags
$permissions = CacheDependency::get('user.1.permissions');

// Invalidate — O(1) operation
CacheDependency::invalidateTags('users');
```

## Tag Dependencies

### How It Works

Tags are stored as metadata with the cached entry. A version counter is maintained for each tag. When you invalidate a tag, the counter increments. On retrieval, cached entries check if their stored tag versions match current versions. If any tag version has increased, the cache is stale.

This is an **O(1) operation** — no iteration over cached items needed.

### Usage

```php
// Cache with tags
CacheDependency::tags(['user.123', 'rbac'])
    ->put('user.123.permissions', $permissions, 3600);

// Cache with multiple tags
CacheDependency::tags(['user.123', 'role.5', 'rbac'])
    ->remember('user.123.permissions', 3600, function () {
        return $this->calculatePermissions(123);
    });

// Retrieve WITHOUT specifying tags
$permissions = CacheDependency::get('user.123.permissions');

// Also works with standard Cache facade
$permissions = Cache::get('user.123.permissions');

// Invalidate all caches with 'rbac' tag (O(1) operation)
CacheDependency::invalidateTags('rbac');

// Invalidate multiple tags at once
CacheDependency::invalidateTags(['user.123', 'role.5']);

// Store indefinitely with tags
CacheDependency::tags('config')->forever('app.settings', $settings);
```

## Database Dependencies

### How It Works

When caching, you provide a SQL query. The query result is stored as a "baseline". On retrieval, the query is re-executed and compared to the baseline. If they differ, the cache is stale.

### Usage

```php
// Simple DB dependency
CacheDependency::db('SELECT MAX(updated_at) FROM roles')
    ->remember('all.roles', 3600, fn() => Role::all());

// DB dependency with parameters
CacheDependency::db(
    'SELECT MAX(updated_at) FROM role_user WHERE user_id = ?',
    [$userId]
)->remember("user.{$userId}.roles", 3600, fn() => $user->roles);

// Using named connection
CacheDependency::db('SELECT COUNT(*) FROM audit_logs')
    ->connection('audit')
    ->put('audit.count', $count, 3600);
```

### Failure Handling

When a database query fails during cache retrieval:

- **Fail Closed (default):** Treat as cache miss, fetch fresh data
- **Fail Open:** Return cached value (prioritize availability)

Configure in `config/cache-dependency.php`:

```php
'db' => [
    'fail_open' => false, // Default: fail closed
],
```

## Combined Dependencies

You can combine tags and database dependencies:

```php
// Cache is invalidated if:
// - Any tag is invalidated, OR
// - DB query result changes
CacheDependency::tags(['rbac', 'permissions'])
    ->db('SELECT MAX(updated_at) FROM permissions')
    ->remember('all.permissions', 3600, fn() => Permission::all());
```

## Tag Design Patterns

Since the package uses explicit tagging (no wildcards), proper tag design is essential:

### RBAC / Permission System

```php
// Caching user permissions
CacheDependency::tags([
    "user.{$userId}.permissions",  // Specific cache
    "user.{$userId}",              // All caches for this user
    'user.permissions',            // All user permission caches
    'rbac',                        // All RBAC-related caches
])->remember("user.{$userId}.permissions", 3600, fn() => $this->calculatePermissions($userId));

// Invalidation scenarios:
CacheDependency::invalidateTags("user.{$userId}");      // User's roles changed
CacheDependency::invalidateTags('user.permissions');    // Permission logic changed
CacheDependency::invalidateTags('rbac');                // Clear all RBAC caches
```

### E-commerce / Product Catalog

```php
// Product cache
CacheDependency::tags([
    "product.{$productId}",
    "category.{$categoryId}.products",
    "vendor.{$vendorId}.products",
    'products',
])->remember("product.{$productId}", 3600, fn() => Product::find($productId));

// Invalidation:
CacheDependency::invalidateTags("product.{$productId}");           // Product updated
CacheDependency::invalidateTags("category.{$categoryId}.products"); // Category products changed
```

### Multi-tenant Application

```php
// Tenant-scoped cache
CacheDependency::tags([
    "tenant.{$tenantId}",
    "tenant.{$tenantId}.settings",
])->remember("tenant.{$tenantId}.config", 3600, fn() => $tenant->settings);

// Invalidation:
CacheDependency::invalidateTags("tenant.{$tenantId}");  // Clear all tenant caches
```

### Tag Naming Conventions

| Pattern | Use Case | Example |
|---------|----------|---------|
| `entity.{id}` | All caches for a specific entity | `user.123`, `product.456` |
| `entity.{id}.aspect` | Specific aspect of an entity | `user.123.permissions` |
| `entity.aspect` | All entities' aspect caches | `user.permissions` |
| `domain` | Domain-wide caches | `rbac`, `products`, `settings` |
| `parent.{id}.children` | Parent-child relationships | `category.5.products` |

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag="cache-dependency-config"
```

Available options:

```php
return [
    // Cache store to use (null = default)
    'store' => env('CACHE_DEPENDENCY_STORE'),

    // Prefix for internal cache keys (tag versions)
    'prefix' => env('CACHE_DEPENDENCY_PREFIX', 'cdep'),

    // Tag version TTL (should be longer than longest cache TTL)
    'tag_version_ttl' => env('CACHE_DEPENDENCY_TAG_TTL', 86400 * 30), // 30 days

    'db' => [
        // Default database connection (null = default)
        'connection' => env('CACHE_DEPENDENCY_DB_CONNECTION'),

        // Query timeout in seconds
        'timeout' => env('CACHE_DEPENDENCY_DB_TIMEOUT', 5),

        // Behavior when DB query fails:
        // - false: Return null (cache miss) - fail closed
        // - true: Return cached value (fail open)
        'fail_open' => env('CACHE_DEPENDENCY_FAIL_OPEN', false),
    ],
];
```

## API Reference

### CacheDependency Facade

```php
// Create pending dependency with tags
CacheDependency::tags(array|string $tags): PendingDependency

// Create pending dependency with DB query
CacheDependency::db(string $sql, array $params = []): PendingDependency

// Retrieve from cache
CacheDependency::get(string $key, mixed $default = null): mixed

// Store in cache (without dependencies)
CacheDependency::put(string $key, mixed $value, ?int $ttl = null): bool

// Remember pattern
CacheDependency::remember(string $key, ?int $ttl, Closure $callback): mixed

// Invalidate tags (O(1))
CacheDependency::invalidateTags(array|string $tags): void

// Get tag version
CacheDependency::getTagVersion(string $tag): int

// Check existence (checks staleness)
CacheDependency::has(string $key): bool

// Remove from cache
CacheDependency::forget(string $key): bool

// Retrieve and delete
CacheDependency::pull(string $key, mixed $default = null): mixed

// Get multiple
CacheDependency::many(array $keys): array

// Clear all
CacheDependency::flush(): bool

// Use different store
CacheDependency::store(?string $name = null): CacheDependencyManager
```

### PendingDependency Methods

```php
// Add tags (chainable)
->tags(array|string $tags): self

// Set DB dependency (chainable)
->db(string $sql, array $params = []): self

// Set DB connection (chainable)
->connection(string $connection): self

// Store with dependencies
->put(string $key, mixed $value, ?int $ttl = null): bool

// Remember with dependencies
->remember(string $key, ?int $ttl, Closure $callback): mixed

// Store forever with dependencies
->forever(string $key, mixed $value): bool

// Store multiple with dependencies
->putMany(array $values, ?int $ttl = null): bool
```

## Testing

```bash
composer test
```

## Credits

- [HL (Lunzai)](https://github.com/lunzai)
- Inspired by [Yii2's Cache Dependencies](https://www.yiiframework.com/doc/guide/2.0/en/caching-data#cache-dependencies)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
