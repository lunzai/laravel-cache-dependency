# Laravel Cache Dependency Package Plan

## Project Overview

**Package Name:** `lunzai/laravel-cache-dependency`  
**Description:** A dependency-based caching system for Laravel inspired by Yii2, featuring tag dependencies with O(1) invalidation and database dependencies for automatic cache freshness.  
**Author:** HL (Lunzai)  
**License:** MIT  

### The Problem

Laravel's built-in cache tags have fundamental design flaws:

1. **Retrieval requires exact tag match** — You must provide the exact same tags used when setting the cache, defeating the purpose of tags as metadata
2. **Limited driver support** — Tags only work with Redis and Memcached, not file/array/database drivers
3. **No database dependencies** — No automatic invalidation when underlying data changes

### The Solution

A separate `CacheDependency` facade that provides:

- **Tag Dependencies:** Tags stored as metadata, retrieval without tags, O(1) invalidation via version counters
- **Database Dependencies:** Automatic cache invalidation when query results change
- **Universal Driver Support:** Works with all Laravel cache drivers

---

## Architecture

### Tag Dependency System

```
┌─────────────────────────────────────────────────────────────┐
│                      CACHE ENTRY                            │
├─────────────────────────────────────────────────────────────┤
│ Key: "user.123.permissions"                                 │
│ Value: {                                                    │
│   data: [serialized permissions],                           │
│   meta: {                                                   │
│     tags: ['user.123', 'role.5', 'rbac'],                   │
│     tag_versions: { 'user.123': 42, 'role.5': 17, 'rbac': 3 }│
│   }                                                         │
│ }                                                           │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                   TAG VERSION STORE                         │
├─────────────────────────────────────────────────────────────┤
│ "cdep:tag:user.123"  →  42                                  │
│ "cdep:tag:role.5"    →  17                                  │
│ "cdep:tag:rbac"      →  3                                   │
└─────────────────────────────────────────────────────────────┘
```

**GET Operation:**
1. Retrieve wrapped cache entry
2. For each stored tag, compare stored version with current version
3. If ANY tag version has increased → return null (stale)
4. Otherwise → return unwrapped data

**INVALIDATE Operation:**
1. Increment the tag's version counter
2. All associated caches become instantly stale
3. Complexity: O(1) — no iteration required

### Database Dependency System

```
┌─────────────────────────────────────────────────────────────┐
│                      CACHE ENTRY                            │
├─────────────────────────────────────────────────────────────┤
│ Key: "all.roles"                                            │
│ Value: {                                                    │
│   data: [serialized roles],                                 │
│   meta: {                                                   │
│     db: {                                                   │
│       sql: "SELECT MAX(updated_at) FROM roles",             │
│       params: [],                                           │
│       baseline: "2025-07-15 10:30:00"                       │
│     }                                                       │
│   }                                                         │
│ }                                                           │
└─────────────────────────────────────────────────────────────┘
```

**GET Operation:**
1. Retrieve wrapped cache entry
2. Execute the stored SQL query
3. Compare result with stored baseline
4. If different → return null (stale)
5. If same → return unwrapped data

---

## API Design

### Tag Dependencies

```php
use Lunzai\CacheDependency\Facades\CacheDependency;

// Setting cache with tags
CacheDependency::tags(['user.123', 'rbac', 'permissions'])
    ->put('user.123.permissions', $permissions, 3600);

CacheDependency::tags(['user.123', 'rbac'])
    ->remember('user.123.roles', 3600, fn() => $user->roles);

CacheDependency::tags(['rbac'])
    ->forever('permission.list', Permission::all());

// Retrieval - NO TAGS REQUIRED
$permissions = CacheDependency::get('user.123.permissions');

// Standard Cache facade also works
$permissions = Cache::get('user.123.permissions');

// Invalidation - O(1) operation
CacheDependency::invalidateTags('rbac');
CacheDependency::invalidateTags(['user.123', 'role.5']);

// Check if cache exists and is valid
CacheDependency::has('user.123.permissions');

// Forget specific key
CacheDependency::forget('user.123.permissions');
```

### Database Dependencies

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

### Combined Dependencies

```php
// Tags + DB dependency
CacheDependency::tags(['rbac', 'permissions'])
    ->db('SELECT MAX(updated_at) FROM permissions')
    ->remember('all.permissions', 3600, fn() => Permission::all());

// Cache is invalidated if:
// - Any tag is invalidated, OR
// - DB query result changes
```

### Additional Methods

```php
// Get with default value
CacheDependency::get('key', 'default');

// Pull (get and forget)
CacheDependency::pull('key');

// Increment/Decrement (no dependency support)
CacheDependency::increment('counter');
CacheDependency::decrement('counter', 5);

// Multiple operations
CacheDependency::many(['key1', 'key2', 'key3']);

CacheDependency::tags(['batch'])
    ->putMany([
        'key1' => $value1,
        'key2' => $value2,
    ], 3600);

// Flush all dependency-tracked caches
CacheDependency::flush();

// Get underlying cache store
CacheDependency::store('redis')->tags(['user'])->put(...);
```

---

## File Structure

```
laravel-cache-dependency/
├── src/
│   ├── CacheDependencyManager.php
│   ├── CacheDependencyServiceProvider.php
│   ├── PendingDependency.php
│   ├── CacheEntryWrapper.php
│   ├── Contracts/
│   │   ├── DependencyInterface.php
│   │   └── CacheDependencyInterface.php
│   ├── Dependencies/
│   │   ├── TagDependency.php
│   │   └── DbDependency.php
│   ├── Facades/
│   │   └── CacheDependency.php
│   └── Exceptions/
│       ├── CacheDependencyException.php
│       └── InvalidDependencyException.php
├── config/
│   └── cache-dependency.php
├── tests/
│   ├── TestCase.php
│   ├── Unit/
│   │   ├── TagDependencyTest.php
│   │   ├── DbDependencyTest.php
│   │   ├── CacheEntryWrapperTest.php
│   │   └── PendingDependencyTest.php
│   └── Feature/
│       ├── TagInvalidationTest.php
│       ├── DbDependencyIntegrationTest.php
│       └── CombinedDependencyTest.php
├── .github/
│   └── workflows/
│       └── tests.yml
├── composer.json
├── phpunit.xml
├── README.md
├── CHANGELOG.md
├── LICENSE
└── .gitignore
```

---

## Core Class Designs

### CacheDependencyManager

```php
<?php

namespace Lunzai\CacheDependency;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class CacheDependencyManager
{
    protected Repository $store;
    protected string $prefix;

    public function __construct(?string $store = null)
    {
        $this->store = Cache::store($store);
        $this->prefix = config('cache-dependency.prefix', 'cdep');
    }

    public function tags(array|string $tags): PendingDependency
    {
        return new PendingDependency($this, (array) $tags, null);
    }

    public function db(string $sql, array $params = []): PendingDependency
    {
        $dbDependency = new Dependencies\DbDependency($sql, $params);
        return new PendingDependency($this, [], $dbDependency);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $wrapper = $this->store->get($key);
        
        if (!$wrapper instanceof CacheEntryWrapper) {
            return $wrapper ?? $default;
        }

        if ($wrapper->isStale($this)) {
            $this->store->forget($key);
            return $default;
        }

        return $wrapper->getData();
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        // Direct put without dependencies
        return $this->store->put($key, $value, $ttl);
    }

    public function invalidateTags(array|string $tags): void
    {
        foreach ((array) $tags as $tag) {
            $versionKey = $this->getTagVersionKey($tag);
            $this->store->increment($versionKey) ?: $this->store->put($versionKey, 1, $this->getTagVersionTtl());
        }
    }

    public function getTagVersion(string $tag): int
    {
        return (int) $this->store->get($this->getTagVersionKey($tag), 0);
    }

    public function getTagVersionKey(string $tag): string
    {
        return "{$this->prefix}:tag:{$tag}";
    }

    protected function getTagVersionTtl(): int
    {
        return config('cache-dependency.tag_version_ttl', 86400 * 30);
    }

    // ... additional methods
}
```

### PendingDependency

```php
<?php

namespace Lunzai\CacheDependency;

class PendingDependency
{
    protected CacheDependencyManager $manager;
    protected array $tags = [];
    protected ?Dependencies\DbDependency $dbDependency = null;
    protected ?string $connection = null;

    public function __construct(
        CacheDependencyManager $manager,
        array $tags = [],
        ?Dependencies\DbDependency $dbDependency = null
    ) {
        $this->manager = $manager;
        $this->tags = $tags;
        $this->dbDependency = $dbDependency;
    }

    public function tags(array|string $tags): self
    {
        $this->tags = array_merge($this->tags, (array) $tags);
        return $this;
    }

    public function db(string $sql, array $params = []): self
    {
        $this->dbDependency = new Dependencies\DbDependency($sql, $params, $this->connection);
        return $this;
    }

    public function connection(string $connection): self
    {
        $this->connection = $connection;
        if ($this->dbDependency) {
            $this->dbDependency->setConnection($connection);
        }
        return $this;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $wrapper = $this->createWrapper($value);
        return $this->manager->getStore()->put($key, $wrapper, $ttl);
    }

    public function remember(string $key, ?int $ttl, \Closure $callback): mixed
    {
        $value = $this->manager->get($key);
        
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);
        
        return $value;
    }

    public function forever(string $key, mixed $value): bool
    {
        $wrapper = $this->createWrapper($value);
        return $this->manager->getStore()->forever($key, $wrapper);
    }

    protected function createWrapper(mixed $value): CacheEntryWrapper
    {
        $tagVersions = [];
        foreach ($this->tags as $tag) {
            $tagVersions[$tag] = $this->manager->getTagVersion($tag);
        }

        $dbBaseline = null;
        if ($this->dbDependency) {
            $dbBaseline = $this->dbDependency->getCurrentValue();
        }

        return new CacheEntryWrapper($value, $this->tags, $tagVersions, $this->dbDependency, $dbBaseline);
    }
}
```

### CacheEntryWrapper

```php
<?php

namespace Lunzai\CacheDependency;

class CacheEntryWrapper
{
    public function __construct(
        protected mixed $data,
        protected array $tags,
        protected array $tagVersions,
        protected ?Dependencies\DbDependency $dbDependency,
        protected mixed $dbBaseline
    ) {}

    public function getData(): mixed
    {
        return $this->data;
    }

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

        // Check DB dependency
        if ($this->dbDependency !== null) {
            $currentValue = $this->dbDependency->getCurrentValue();
            if ($currentValue !== $this->dbBaseline) {
                return true;
            }
        }

        return false;
    }

    public function getTags(): array
    {
        return $this->tags;
    }
}
```

---

## Configuration

```php
<?php
// config/cache-dependency.php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store to use for dependency tracking. Set to null to use
    | Laravel's default cache store.
    |
    */
    'store' => env('CACHE_DEPENDENCY_STORE', null),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all cache dependency internal keys (tag versions, etc.)
    |
    */
    'prefix' => env('CACHE_DEPENDENCY_PREFIX', 'cdep'),

    /*
    |--------------------------------------------------------------------------
    | Tag Version TTL
    |--------------------------------------------------------------------------
    |
    | How long to keep tag version counters (in seconds). Should be longer
    | than your longest cache TTL to prevent false cache hits.
    |
    */
    'tag_version_ttl' => env('CACHE_DEPENDENCY_TAG_TTL', 86400 * 30), // 30 days

    /*
    |--------------------------------------------------------------------------
    | Database Dependency Settings
    |--------------------------------------------------------------------------
    */
    'db' => [
        // Default database connection for DB dependencies (null = default)
        'connection' => env('CACHE_DEPENDENCY_DB_CONNECTION', null),
        
        // Query timeout in seconds
        'timeout' => env('CACHE_DEPENDENCY_DB_TIMEOUT', 5),
        
        // Behavior when DB query fails:
        // - false: Return null (cache miss)
        // - true: Return cached value (fail open)
        'fail_open' => env('CACHE_DEPENDENCY_FAIL_OPEN', false),
    ],
];
```

---

## Tag Design Patterns

Since the package uses explicit tagging (no wildcards), proper tag design is essential for effective cache invalidation.

### RBAC / Permission System

```php
// Caching user permissions
CacheDependency::tags([
    "user.{$userId}.permissions",  // Specific cache
    "user.{$userId}",              // All caches for this user
    'user.permissions',            // All user permission caches
    'rbac',                        // All RBAC-related caches
])->remember("user.{$userId}.permissions", 3600, fn() => $this->calculatePermissions($userId));

// Caching role data
CacheDependency::tags([
    "role.{$roleId}",
    'roles',
    'rbac',
])->remember("role.{$roleId}", 3600, fn() => Role::with('permissions')->find($roleId));

// Invalidation scenarios:
CacheDependency::invalidateTags("user.{$userId}");      // User's roles changed
CacheDependency::invalidateTags("role.{$roleId}");      // Role permissions changed
CacheDependency::invalidateTags('user.permissions');    // Permission logic changed
CacheDependency::invalidateTags('rbac');                // Nuclear option - clear all
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

// Category listing
CacheDependency::tags([
    "category.{$categoryId}",
    "category.{$categoryId}.products",
    'categories',
])->remember("category.{$categoryId}.listing", 3600, fn() => $this->getCategoryListing($categoryId));

// Invalidation scenarios:
CacheDependency::invalidateTags("product.{$productId}");           // Product updated
CacheDependency::invalidateTags("category.{$categoryId}.products"); // Category products changed
CacheDependency::invalidateTags("vendor.{$vendorId}.products");    // Vendor products changed
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

---

## Testing Strategy

### Unit Tests

```php
// tests/Unit/TagDependencyTest.php
class TagDependencyTest extends TestCase
{
    public function test_cache_hit_when_tags_unchanged(): void
    {
        CacheDependency::tags(['test'])->put('key', 'value', 3600);
        
        $this->assertEquals('value', CacheDependency::get('key'));
    }

    public function test_cache_miss_when_tag_invalidated(): void
    {
        CacheDependency::tags(['test'])->put('key', 'value', 3600);
        CacheDependency::invalidateTags('test');
        
        $this->assertNull(CacheDependency::get('key'));
    }

    public function test_partial_tag_invalidation(): void
    {
        CacheDependency::tags(['a', 'b'])->put('key1', 'value1', 3600);
        CacheDependency::tags(['b', 'c'])->put('key2', 'value2', 3600);
        
        CacheDependency::invalidateTags('a');
        
        $this->assertNull(CacheDependency::get('key1'));
        $this->assertEquals('value2', CacheDependency::get('key2'));
    }

    public function test_multiple_tag_invalidation(): void
    {
        CacheDependency::tags(['a'])->put('key1', 'value1', 3600);
        CacheDependency::tags(['b'])->put('key2', 'value2', 3600);
        
        CacheDependency::invalidateTags(['a', 'b']);
        
        $this->assertNull(CacheDependency::get('key1'));
        $this->assertNull(CacheDependency::get('key2'));
    }
}
```

### Feature Tests

```php
// tests/Feature/DbDependencyIntegrationTest.php
class DbDependencyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_invalidates_when_db_changes(): void
    {
        $user = User::factory()->create();
        
        CacheDependency::db('SELECT MAX(updated_at) FROM users WHERE id = ?', [$user->id])
            ->put("user.{$user->id}", $user->toArray(), 3600);
        
        // Cache hit
        $this->assertNotNull(CacheDependency::get("user.{$user->id}"));
        
        // Update user
        $user->update(['name' => 'New Name']);
        
        // Cache miss
        $this->assertNull(CacheDependency::get("user.{$user->id}"));
    }

    public function test_combined_dependencies(): void
    {
        $user = User::factory()->create();
        
        CacheDependency::tags(['users'])
            ->db('SELECT MAX(updated_at) FROM users')
            ->put('all.users', User::all()->toArray(), 3600);
        
        // Invalidate via tag
        CacheDependency::invalidateTags('users');
        $this->assertNull(CacheDependency::get('all.users'));
        
        // Re-cache
        CacheDependency::tags(['users'])
            ->db('SELECT MAX(updated_at) FROM users')
            ->put('all.users', User::all()->toArray(), 3600);
        
        // Invalidate via DB change
        User::factory()->create();
        $this->assertNull(CacheDependency::get('all.users'));
    }
}
```

---

## GitHub Actions CI

```yaml
# .github/workflows/tests.yml
name: Tests

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest

    strategy:
      fail-fast: true
      matrix:
        php: [8.2, 8.3, 8.4]
        laravel: [11.*, 12.*]
        include:
          - laravel: 11.*
            testbench: 9.*
          - laravel: 12.*
            testbench: 10.*

    name: PHP ${{ matrix.php }} - Laravel ${{ matrix.laravel }}

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, pdo, sqlite
          coverage: xdebug

      - name: Install dependencies
        run: |
          composer require "laravel/framework:${{ matrix.laravel }}" "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update
          composer update --prefer-dist --no-interaction

      - name: Run tests
        run: vendor/bin/phpunit --coverage-clover coverage.xml

      - name: Upload coverage
        uses: codecov/codecov-action@v4
        with:
          file: coverage.xml

  code-style:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v4
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.3
      
      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction
      
      - name: Run Pint
        run: vendor/bin/pint --test
```

---

## composer.json

```json
{
    "name": "lunzai/laravel-cache-dependency",
    "description": "Dependency-based caching for Laravel with tag and database dependencies inspired by Yii2",
    "keywords": ["laravel", "cache", "dependency", "tags", "invalidation"],
    "license": "MIT",
    "authors": [
        {
            "name": "HL",
            "email": "your-email@example.com",
            "homepage": "https://lunzai.com"
        }
    ],
    "homepage": "https://github.com/lunzai/laravel-cache-dependency",
    "require": {
        "php": "^8.2",
        "illuminate/cache": "^11.0|^12.0",
        "illuminate/contracts": "^11.0|^12.0",
        "illuminate/database": "^11.0|^12.0",
        "illuminate/support": "^11.0|^12.0"
    },
    "require-dev": {
        "laravel/pint": "^1.13",
        "orchestra/testbench": "^9.0|^10.0",
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Lunzai\\CacheDependency\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Lunzai\\CacheDependency\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Lunzai\\CacheDependency\\CacheDependencyServiceProvider"
            ],
            "aliases": {
                "CacheDependency": "Lunzai\\CacheDependency\\Facades\\CacheDependency"
            }
        }
    },
    "config": {
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

---

## Implementation Phases

### Phase 1: Core Foundation (MVP)
- [ ] Basic project structure and composer setup
- [ ] CacheDependencyManager with tag support
- [ ] PendingDependency fluent interface
- [ ] CacheEntryWrapper for metadata storage
- [ ] Tag invalidation (O(1))
- [ ] Basic unit tests
- [ ] Service provider and facade

### Phase 2: Database Dependencies
- [ ] DbDependency class
- [ ] SQL query execution and baseline comparison
- [ ] Connection configuration support
- [ ] Fail-open/fail-closed behavior
- [ ] Integration tests with SQLite

### Phase 3: Polish & Documentation
- [ ] Combined dependencies (tags + db)
- [ ] Edge case handling
- [ ] Comprehensive README
- [ ] API documentation
- [ ] Tag design patterns guide

### Phase 4: Release
- [ ] Final testing across PHP/Laravel versions
- [ ] CHANGELOG
- [ ] GitHub release
- [ ] Packagist submission

---

## Publishing to Packagist: Step-by-Step Guide

### Prerequisites

1. **GitHub Account** — Your package must be hosted on GitHub (or GitLab/Bitbucket)
2. **Packagist Account** — Create one at https://packagist.org using your GitHub account
3. **Valid composer.json** — Must have `name`, `description`, `license`, and `autoload`

### Step 1: Prepare Your Repository

```bash
# Initialize git if not already done
cd laravel-cache-dependency
git init

# Create .gitignore
cat > .gitignore << 'EOF'
/vendor/
/node_modules/
.phpunit.result.cache
.php-cs-fixer.cache
composer.lock
.env
.DS_Store
Thumbs.db
coverage/
.idea/
.vscode/
EOF

# Initial commit
git add .
git commit -m "Initial commit"

# Create GitHub repository and push
gh repo create lunzai/laravel-cache-dependency --public --source=. --push
# Or manually create on GitHub and:
# git remote add origin git@github.com:lunzai/laravel-cache-dependency.git
# git push -u origin main
```

### Step 2: Validate composer.json

```bash
# Validate your composer.json
composer validate

# Should output: "./composer.json is valid"
```

Required fields for Packagist:
- `name` — Format: `vendor/package-name` (e.g., `lunzai/laravel-cache-dependency`)
- `description` — Brief description of the package
- `license` — SPDX license identifier (e.g., `MIT`)
- `autoload` — PSR-4 autoloading configuration

### Step 3: Create a Release Tag

Packagist uses Git tags for versioning. Follow semantic versioning (semver):

```bash
# Tag your first release
git tag -a v1.0.0 -m "Initial release"
git push origin v1.0.0

# For pre-releases:
git tag -a v0.1.0-alpha -m "Alpha release for testing"
git push origin v0.1.0-alpha
```

### Step 4: Submit to Packagist

1. Go to https://packagist.org
2. Click **Login** → Sign in with GitHub
3. Click **Submit** (top menu)
4. Enter your repository URL: `https://github.com/lunzai/laravel-cache-dependency`
5. Click **Check** → Packagist validates your composer.json
6. Click **Submit**

Your package is now live at: `https://packagist.org/packages/lunzai/laravel-cache-dependency`

### Step 5: Enable Auto-Update (GitHub Webhook)

Packagist needs to know when you push new tags:

**Option A: GitHub Integration (Recommended)**
1. On Packagist, go to your package page
2. Click **Settings** → **GitHub Sync**
3. Click **Enable GitHub Hook**
4. Authorize Packagist to access your repository

**Option B: Manual Webhook**
1. On Packagist, copy your API token from **Profile** → **API Token**
2. Go to GitHub repo → **Settings** → **Webhooks** → **Add webhook**
3. Configure:
   - Payload URL: `https://packagist.org/api/github?username=YOUR_PACKAGIST_USERNAME`
   - Content type: `application/json`
   - Secret: Your Packagist API token
   - Events: **Just the push event**
4. Click **Add webhook**

### Step 6: Test Installation

```bash
# In a fresh Laravel project
composer require lunzai/laravel-cache-dependency

# For development version
composer require lunzai/laravel-cache-dependency:dev-main
```

### Step 7: Releasing Updates

```bash
# Make changes and commit
git add .
git commit -m "Add new feature X"
git push origin main

# Create new version tag
git tag -a v1.1.0 -m "Add feature X"
git push origin v1.1.0

# Packagist auto-updates within minutes (if webhook configured)
```

### Version Numbering Guidelines

| Version | When to Use |
|---------|-------------|
| `0.x.x` | Initial development, API may change |
| `1.0.0` | First stable release |
| `1.0.1` | Bug fixes only (patch) |
| `1.1.0` | New features, backward compatible (minor) |
| `2.0.0` | Breaking changes (major) |
| `x.x.x-alpha` | Early testing |
| `x.x.x-beta` | Feature complete, testing |
| `x.x.x-RC1` | Release candidate |

### Packagist Best Practices

1. **Always tag releases** — Don't just push to main; create version tags
2. **Write a good README** — It's displayed on Packagist
3. **Add badges** — Build status, coverage, version, etc.
4. **Include CHANGELOG** — Document changes between versions
5. **Set up CI** — Run tests before tagging releases
6. **Use branch aliases** — In composer.json for dev versions:

```json
{
    "extra": {
        "branch-alias": {
            "dev-main": "1.x-dev"
        }
    }
}
```

### Troubleshooting

**"Package not found" after submission:**
- Wait 1-2 minutes for Packagist to index
- Check if webhook triggered successfully
- Manually trigger update: Packagist → Your Package → **Update**

**"Invalid composer.json":**
- Run `composer validate --strict`
- Ensure `name` matches your GitHub username/repo structure

**Updates not appearing:**
- Check webhook delivery in GitHub → Settings → Webhooks
- Manually click **Update** on Packagist package page

---

## README Template

```markdown
# Laravel Cache Dependency

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lunzai/laravel-cache-dependency.svg)](https://packagist.org/packages/lunzai/laravel-cache-dependency)
[![Tests](https://github.com/lunzai/laravel-cache-dependency/actions/workflows/tests.yml/badge.svg)](https://github.com/lunzai/laravel-cache-dependency/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/lunzai/laravel-cache-dependency.svg)](https://packagist.org/packages/lunzai/laravel-cache-dependency)
[![License](https://img.shields.io/packagist/l/lunzai/laravel-cache-dependency.svg)](https://packagist.org/packages/lunzai/laravel-cache-dependency)

A dependency-based caching system for Laravel inspired by Yii2. Features tag dependencies with O(1) invalidation and database dependencies for automatic cache freshness.

## Why This Package?

Laravel's built-in cache tags have fundamental limitations:

- **Retrieval requires exact tag match** — You must provide the exact same tags used when caching
- **Limited driver support** — Only works with Redis and Memcached
- **No database dependencies** — No automatic invalidation when data changes

This package solves all three problems.

## Installation

\`\`\`bash
composer require lunzai/laravel-cache-dependency
\`\`\`

Optionally publish the config:

\`\`\`bash
php artisan vendor:publish --tag="cache-dependency-config"
\`\`\`

## Quick Start

\`\`\`php
use Lunzai\CacheDependency\Facades\CacheDependency;

// Cache with tags
CacheDependency::tags(['users', 'permissions'])
    ->put('user.1.permissions', $permissions, 3600);

// Retrieve WITHOUT tags
$permissions = CacheDependency::get('user.1.permissions');

// Invalidate — O(1) operation
CacheDependency::invalidateTags('users');
\`\`\`

## Documentation

See [full documentation](https://github.com/lunzai/laravel-cache-dependency/wiki) for:

- Tag Dependencies
- Database Dependencies
- Combined Dependencies
- Tag Design Patterns

## License

MIT License. See [LICENSE](LICENSE) for details.
```

---

## Summary

This plan provides a complete roadmap for building `lunzai/laravel-cache-dependency`:

1. **Architecture** — Version-based tag invalidation (O(1), works with all drivers)
2. **API Design** — Fluent interface, separate facade, tags + DB dependencies
3. **Implementation** — Core classes, configuration, testing strategy
4. **Tag Patterns** — Best practices for RBAC, e-commerce, multi-tenant apps
5. **Publishing** — Complete step-by-step guide for Packagist

Start with Phase 1 (MVP with tag dependencies), validate the approach, then iterate.
