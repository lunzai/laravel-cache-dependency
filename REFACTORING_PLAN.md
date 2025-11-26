# 🚀 Refactoring Implementation Plan

**Based on:** ARCHITECTURE_REVIEW.md
**Goal:** Transform the codebase to be fully extensible and SOLID-compliant
**Timeline:** 3-5 days (20-30 development hours)
**Risk Level:** Medium - Requires comprehensive testing

---

## 📋 Overview

This plan addresses the critical architectural issues identified in the code review, prioritizing extensibility and SOLID principles while maintaining backward compatibility where possible.

### Success Criteria
- ✅ All 20 existing tests pass
- ✅ Can add new dependency type without modifying core files
- ✅ `DbDependency::isStale()` works correctly
- ✅ Polymorphic dependency handling throughout
- ✅ No breaking changes to public API

---

## 🎯 Phase 1: Critical Architecture Refactoring (Days 1-2)

### **Task 1.1: Refactor DependencyInterface**
**Priority:** 🔴 Critical
**Estimated Time:** 2 hours
**Risk:** Low

#### Current State
```php
interface DependencyInterface
{
    public function isStale(CacheDependencyManager $manager): bool;
    public function toArray(): array;
}
```

#### Problem
`DbDependency` needs baseline comparison, but interface doesn't support it. This forces `DbDependency::isStale()` to always return `false`.

#### Solution: Add Baseline Support

**File:** `src/Contracts/DependencyInterface.php`

```php
<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Contracts;

use Lunzai\CacheDependency\CacheDependencyManager;

/**
 * Interface for cache dependencies.
 *
 * All dependency types must implement this interface to participate
 * in cache staleness detection.
 */
interface DependencyInterface
{
    /**
     * Check if this dependency has become stale.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager
     * @param  mixed  $baseline  The baseline value captured at cache time (optional)
     * @return bool True if stale, false otherwise
     */
    public function isStale(CacheDependencyManager $manager, mixed $baseline = null): bool;

    /**
     * Capture the current baseline value for this dependency.
     *
     * This value will be stored with the cached entry and passed to
     * isStale() during staleness checks.
     *
     * @return mixed The baseline value (can be anything serializable)
     */
    public function captureBaseline(): mixed;

    /**
     * Convert the dependency to an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
```

**Why Two Methods?**
- `captureBaseline()` - Called when caching (PendingDependency)
- `isStale($manager, $baseline)` - Called when retrieving (CacheEntryWrapper)

This separates concerns and makes the interface clearer.

#### Files to Modify
1. ✏️ `src/Contracts/DependencyInterface.php` - Add `captureBaseline()`, update `isStale()`
2. ✏️ `src/Dependencies/TagDependency.php` - Implement new interface
3. ✏️ `src/Dependencies/DbDependency.php` - Implement new interface

#### Acceptance Criteria
- [ ] Interface updated with both methods
- [ ] `TagDependency` implements `captureBaseline()` returning current tag versions
- [ ] `DbDependency` implements `captureBaseline()` returning current DB value
- [ ] `DbDependency::isStale()` compares baseline with current value correctly

---

### **Task 1.2: Refactor CacheEntryWrapper to Use Dependency Array**
**Priority:** 🔴 Critical
**Estimated Time:** 4 hours
**Risk:** High (touches core logic)

#### Current State
```php
class CacheEntryWrapper
{
    public function __construct(
        protected mixed $data,
        protected array $tags,
        protected array $tagVersions,
        protected ?DbDependency $dbDependency,
        protected mixed $dbBaseline
    ) {}
}
```

#### Target State
```php
class CacheEntryWrapper
{
    /**
     * @param mixed $data The cached value
     * @param array<DependencyInterface> $dependencies Array of dependencies with baselines
     */
    public function __construct(
        protected mixed $data,
        protected array $dependencies = []
    ) {}
}
```

#### Implementation Steps

**Step 1:** Update Constructor and Properties

```php
<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency;

use Lunzai\CacheDependency\Contracts\DependencyInterface;

/**
 * Wraps cached data with dependency metadata.
 */
class CacheEntryWrapper
{
    /**
     * Create a new cache entry wrapper.
     *
     * @param  mixed  $data  The actual cached data
     * @param  array<array{dependency: DependencyInterface, baseline: mixed}>  $dependencies
     */
    public function __construct(
        protected mixed $data,
        protected array $dependencies = []
    ) {}

    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Get all dependencies (for debugging/inspection).
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
     * An entry is stale if ANY dependency reports staleness.
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
                $failOpen = config('cache-dependency.fail_open', false);

                if (!$failOpen) {
                    // Fail closed: treat as stale (cache miss)
                    return true;
                }

                // Fail open: log and continue
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
     */
    public function __unserialize(array $data): void
    {
        $this->data = $data['data'];
        $this->dependencies = $data['dependencies'] ?? [];
    }
}
```

**Step 2:** Update PendingDependency to Build Dependency Array

```php
<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency;

use Closure;
use Lunzai\CacheDependency\Contracts\DependencyInterface;
use Lunzai\CacheDependency\Dependencies\DbDependency;
use Lunzai\CacheDependency\Dependencies\TagDependency;

class PendingDependency
{
    /**
     * @param  CacheDependencyManager  $manager
     * @param  array<DependencyInterface>  $dependencies  Array of dependency instances
     */
    public function __construct(
        protected CacheDependencyManager $manager,
        protected array $dependencies = []
    ) {}

    /**
     * Add tags to this pending dependency.
     */
    public function tags(array|string $tags): self
    {
        // Check if we already have a TagDependency
        $tagDependency = $this->findDependency(TagDependency::class);

        if ($tagDependency) {
            // Merge with existing tags
            $tagDependency->addTags((array) $tags);
        } else {
            // Create new TagDependency
            $this->dependencies[] = new TagDependency((array) $tags);
        }

        return $this;
    }

    /**
     * Set a database dependency for this pending operation.
     */
    public function db(string $sql, array $params = [], ?string $connection = null): self
    {
        $this->dependencies[] = new DbDependency($sql, $params, $connection);

        return $this;
    }

    /**
     * Store an item in the cache with dependencies.
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $wrapper = $this->createWrapper($value);

        return $this->manager->getStore()->put($key, $wrapper, $ttl);
    }

    // ... other methods (remember, forever, putMany)

    /**
     * Create a cache entry wrapper with current dependency baselines.
     */
    protected function createWrapper(mixed $value): CacheEntryWrapper
    {
        $dependenciesWithBaselines = [];

        foreach ($this->dependencies as $dependency) {
            try {
                $baseline = $dependency->captureBaseline();

                $dependenciesWithBaselines[] = [
                    'dependency' => $dependency,
                    'baseline' => $baseline,
                ];
            } catch (\Throwable $e) {
                // Handle baseline capture failure
                $allowFailure = config('cache-dependency.allow_baseline_failure', false);

                if (!$allowFailure) {
                    throw $e;
                }

                // Log and skip this dependency
                if (config('cache-dependency.log_failures', true)) {
                    logger()->warning('Failed to capture dependency baseline', [
                        'dependency' => get_class($dependency),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return new CacheEntryWrapper($value, $dependenciesWithBaselines);
    }

    /**
     * Find a dependency by class name.
     */
    protected function findDependency(string $className): ?DependencyInterface
    {
        foreach ($this->dependencies as $dependency) {
            if ($dependency instanceof $className) {
                return $dependency;
            }
        }

        return null;
    }
}
```

**Step 3:** Update TagDependency

```php
<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Dependencies;

use Lunzai\CacheDependency\CacheDependencyManager;
use Lunzai\CacheDependency\Contracts\DependencyInterface;

class TagDependency implements DependencyInterface
{
    /**
     * @param  array<string>  $tags  Tags to track
     */
    public function __construct(
        protected array $tags
    ) {}

    /**
     * Add more tags to this dependency.
     */
    public function addTags(array $tags): void
    {
        $this->tags = array_unique(array_merge($this->tags, $tags));
    }

    /**
     * Capture current tag versions as baseline.
     */
    public function captureBaseline(): mixed
    {
        // This will be called by PendingDependency during caching
        // We need the manager to get current versions
        // This is a design flaw - captureBaseline needs manager too!

        // BETTER: Store manager reference or change interface
        return null; // Will fix in next iteration
    }

    /**
     * Check if this dependency is stale.
     */
    public function isStale(CacheDependencyManager $manager, mixed $baseline = null): bool
    {
        if (!is_array($baseline)) {
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

    public function toArray(): array
    {
        return [
            'type' => 'tag',
            'tags' => $this->tags,
        ];
    }

    public function __serialize(): array
    {
        return ['tags' => $this->tags];
    }

    public function __unserialize(array $data): void
    {
        $this->tags = $data['tags'];
    }
}
```

**Step 4:** Update DbDependency

```php
<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Dependencies;

use Illuminate\Support\Facades\DB;
use Lunzai\CacheDependency\CacheDependencyManager;
use Lunzai\CacheDependency\Contracts\DependencyInterface;
use Lunzai\CacheDependency\Exceptions\DatabaseDependencyException;

class DbDependency implements DependencyInterface
{
    public function __construct(
        protected string $sql,
        protected array $params = [],
        protected ?string $connection = null
    ) {}

    /**
     * Capture current database value as baseline.
     */
    public function captureBaseline(): mixed
    {
        return $this->fetchCurrentValue();
    }

    /**
     * Check if database value has changed from baseline.
     */
    public function isStale(CacheDependencyManager $manager, mixed $baseline = null): bool
    {
        try {
            $currentValue = $this->fetchCurrentValue();

            return $currentValue !== $baseline;
        } catch (DatabaseDependencyException $e) {
            // Let caller handle via fail_open config
            throw $e;
        }
    }

    /**
     * Fetch the current value from database.
     */
    protected function fetchCurrentValue(): mixed
    {
        try {
            $connection = $this->connection ?? config('cache-dependency.db.connection');

            $result = DB::connection($connection)
                ->select($this->sql, $this->params);

            if (empty($result)) {
                return null;
            }

            $firstRow = (array) $result[0];

            return reset($firstRow);
        } catch (\Throwable $e) {
            throw new DatabaseDependencyException(
                "Database dependency query failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function setConnection(string $connection): void
    {
        $this->connection = $connection;
    }

    public function toArray(): array
    {
        return [
            'type' => 'db',
            'sql' => $this->sql,
            'params' => $this->params,
            'connection' => $this->connection,
        ];
    }

    public function __serialize(): array
    {
        return [
            'sql' => $this->sql,
            'params' => $this->params,
            'connection' => $this->connection,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->sql = $data['sql'];
        $this->params = $data['params'];
        $this->connection = $data['connection'];
    }
}
```

#### IMPORTANT: Design Flaw in captureBaseline()

**Problem:** `TagDependency::captureBaseline()` needs `CacheDependencyManager` to get current versions, but interface doesn't provide it.

**Solution Options:**

**Option A:** Pass manager to `captureBaseline()`
```php
public function captureBaseline(CacheDependencyManager $manager): mixed;
```

**Option B:** Dependencies hold manager reference (dependency injection)
```php
class TagDependency {
    public function __construct(
        protected CacheDependencyManager $manager,
        protected array $tags
    ) {}
}
```

**Option C:** PendingDependency handles baseline capture (current approach)
```php
// PendingDependency already has $manager reference
protected function createWrapper(mixed $value): CacheEntryWrapper
{
    foreach ($this->dependencies as $dependency) {
        if ($dependency instanceof TagDependency) {
            $baseline = $this->captureTagBaseline($dependency);
        } elseif ($dependency instanceof DbDependency) {
            $baseline = $dependency->captureBaseline();
        }
    }
}
```

**Recommendation:** Use **Option A** - Update interface to pass manager:

```php
interface DependencyInterface
{
    public function captureBaseline(CacheDependencyManager $manager): mixed;
    public function isStale(CacheDependencyManager $manager, mixed $baseline = null): bool;
    public function toArray(): array;
}
```

This makes both methods consistent and explicit.

#### Files to Modify
1. ✏️ `src/Contracts/DependencyInterface.php`
2. ✏️ `src/CacheEntryWrapper.php`
3. ✏️ `src/PendingDependency.php`
4. ✏️ `src/Dependencies/TagDependency.php`
5. ✏️ `src/Dependencies/DbDependency.php`
6. ✏️ `src/CacheDependencyManager.php` (update constructor for PendingDependency)

#### Acceptance Criteria
- [ ] `CacheEntryWrapper` stores array of dependencies instead of individual properties
- [ ] `isStale()` iterates over dependencies polymorphically
- [ ] `PendingDependency` builds dependency array
- [ ] Serialization/deserialization works correctly
- [ ] All 20 existing tests pass
- [ ] Can add new dependency without modifying CacheEntryWrapper

---

### **Task 1.3: Update CacheDependencyManager Factory Methods**
**Priority:** 🟡 Medium
**Estimated Time:** 1 hour
**Risk:** Low

#### Update Manager to Pass Itself to PendingDependency

Since dependencies now need manager reference for `captureBaseline()`, ensure it's passed correctly:

```php
// src/CacheDependencyManager.php

public function tags(array|string $tags): PendingDependency
{
    $dependency = new TagDependency((array) $tags);
    return new PendingDependency($this, [$dependency]);
}

public function db(string $sql, array $params = []): PendingDependency
{
    $dependency = new DbDependency($sql, $params);
    return new PendingDependency($this, [$dependency]);
}
```

---

### **Task 1.4: Add Configuration for Error Handling**
**Priority:** 🟡 Medium
**Estimated Time:** 30 minutes
**Risk:** Low

#### Update Config File

```php
// config/cache-dependency.php

return [
    'store' => env('CACHE_DEPENDENCY_STORE'),
    'prefix' => env('CACHE_DEPENDENCY_PREFIX', 'cdep'),
    'tag_version_ttl' => env('CACHE_DEPENDENCY_TAG_VERSION_TTL', 86400 * 30),

    'db' => [
        'connection' => env('CACHE_DEPENDENCY_DB_CONNECTION'),
        'fail_open' => env('CACHE_DEPENDENCY_FAIL_OPEN', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    */

    // Log dependency failures (staleness checks and baseline captures)
    'log_failures' => env('CACHE_DEPENDENCY_LOG_FAILURES', true),

    // Allow baseline capture to fail gracefully (skip dependency instead of throwing)
    'allow_baseline_failure' => env('CACHE_DEPENDENCY_ALLOW_BASELINE_FAILURE', false),

    // Global fail_open setting (can be overridden per-dependency type)
    'fail_open' => env('CACHE_DEPENDENCY_FAIL_OPEN', false),
];
```

---

## 🔧 Phase 2: Testing & Validation (Day 3)

### **Task 2.1: Update Existing Tests**
**Priority:** 🔴 Critical
**Estimated Time:** 3 hours
**Risk:** Medium

#### Test Files to Update
1. `tests/Feature/TagInvalidationTest.php`
2. `tests/Feature/DbDependencyIntegrationTest.php`
3. `tests/Feature/CombinedDependencyTest.php`

#### Changes Needed
- Tests should still pass with new architecture
- Verify serialization/deserialization works
- Add assertions for new `getDependencies()` method

#### Example Test Updates

```php
// tests/Feature/TagInvalidationTest.php

public function test_cache_with_single_tag_invalidates(): void
{
    CacheDependency::tags('users')->put('user.1', 'John Doe', 3600);

    $this->assertEquals('John Doe', CacheDependency::get('user.1'));

    // Invalidate the tag
    CacheDependency::invalidateTags('users');

    $this->assertNull(CacheDependency::get('user.1'));

    // NEW: Verify dependency structure
    $rawWrapper = Cache::get('user.1'); // Get before staleness check
    if ($rawWrapper instanceof CacheEntryWrapper) {
        $deps = $rawWrapper->getDependencies();
        $this->assertCount(1, $deps);
        $this->assertInstanceOf(TagDependency::class, $deps[0]['dependency']);
    }
}
```

---

### **Task 2.2: Add New Tests for Extensibility**
**Priority:** 🔴 Critical
**Estimated Time:** 2 hours
**Risk:** Low

#### Create Mock Custom Dependency

```php
// tests/Fixtures/CustomDependency.php

namespace Lunzai\CacheDependency\Tests\Fixtures;

use Lunzai\CacheDependency\CacheDependencyManager;
use Lunzai\CacheDependency\Contracts\DependencyInterface;

/**
 * Mock dependency for testing extensibility.
 * Simulates a file-based dependency.
 */
class FileDependency implements DependencyInterface
{
    private static int $version = 0;

    public function __construct(
        protected string $filepath
    ) {}

    public static function incrementVersion(): void
    {
        self::$version++;
    }

    public function captureBaseline(CacheDependencyManager $manager): mixed
    {
        return self::$version;
    }

    public function isStale(CacheDependencyManager $manager, mixed $baseline = null): bool
    {
        return self::$version > ($baseline ?? 0);
    }

    public function toArray(): array
    {
        return [
            'type' => 'file',
            'filepath' => $this->filepath,
        ];
    }

    public function __serialize(): array
    {
        return ['filepath' => $this->filepath];
    }

    public function __unserialize(array $data): void
    {
        $this->filepath = $data['filepath'];
    }
}
```

#### Create Extensibility Test

```php
// tests/Feature/ExtensibilityTest.php

namespace Lunzai\CacheDependency\Tests\Feature;

use Lunzai\CacheDependency\PendingDependency;
use Lunzai\CacheDependency\Facades\CacheDependency;
use Lunzai\CacheDependency\Tests\Fixtures\FileDependency;
use Lunzai\CacheDependency\Tests\TestCase;

class ExtensibilityTest extends TestCase
{
    public function test_can_add_custom_dependency_without_modifying_core(): void
    {
        FileDependency::incrementVersion(); // version = 1

        // Add custom dependency via PendingDependency
        $pending = new PendingDependency(
            app(\Lunzai\CacheDependency\CacheDependencyManager::class),
            [new FileDependency('/tmp/test.txt')]
        );

        $pending->put('test.key', 'test value', 3600);

        // Should retrieve successfully
        $this->assertEquals('test value', CacheDependency::get('test.key'));

        // Simulate file change
        FileDependency::incrementVersion(); // version = 2

        // Should now be stale
        $this->assertNull(CacheDependency::get('test.key'));
    }

    public function test_can_combine_custom_and_builtin_dependencies(): void
    {
        FileDependency::incrementVersion();

        $manager = app(\Lunzai\CacheDependency\CacheDependencyManager::class);
        $pending = new PendingDependency($manager, [
            new FileDependency('/tmp/test.txt')
        ]);

        // Chain with built-in tag dependency
        $pending->tags('users')->put('combined.key', 'value', 3600);

        $this->assertEquals('value', CacheDependency::get('combined.key'));

        // Invalidate via tag
        CacheDependency::invalidateTags('users');

        $this->assertNull(CacheDependency::get('combined.key'));
    }
}
```

#### Acceptance Criteria
- [ ] Can create custom dependency implementing interface
- [ ] Custom dependency works without modifying CacheEntryWrapper
- [ ] Can combine custom + built-in dependencies
- [ ] All tests pass

---

## 🎨 Phase 3: Polish & Documentation (Day 4)

### **Task 3.1: Fix Race Condition in Tag Invalidation**
**Priority:** 🟡 Medium
**Estimated Time:** 2 hours
**Risk:** Low

#### Implementation

```php
// src/CacheDependencyManager.php

public function invalidateTags(array|string $tags): void
{
    foreach ((array) $tags as $tag) {
        $versionKey = $this->getTagVersionKey($tag);

        // Try atomic increment first
        $newVersion = $this->store->increment($versionKey);

        if ($newVersion === false) {
            // Atomic increment not supported - use locks
            $lock = Cache::lock("invalidate:tag:{$tag}", 5);

            try {
                // Block until lock acquired (max 5 seconds)
                $lock->block(5);

                $currentVersion = (int) $this->store->get($versionKey, 0);
                $this->store->put($versionKey, $currentVersion + 1, $this->getTagVersionTtl());
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                // Could not acquire lock - log warning
                if (config('cache-dependency.log_failures', true)) {
                    logger()->warning('Failed to acquire lock for tag invalidation', [
                        'tag' => $tag,
                    ]);
                }
            } finally {
                optional($lock)->release();
            }
        }
    }
}
```

---

### **Task 3.2: Standardize Method Naming**
**Priority:** 🟢 Low
**Estimated Time:** 1 hour
**Risk:** Low (breaking change)

#### Rename Methods for Clarity

| Old Name | New Name | Reason |
|----------|----------|--------|
| `getData()` | `unwrap()` | More semantic for wrapper |
| `getCurrentValue()` | `fetchCurrentValue()` | Indicates I/O |

**Decision:** Keep existing names for BC, add aliases:

```php
// CacheEntryWrapper.php
public function unwrap(): mixed
{
    return $this->getData();
}

public function getData(): mixed
{
    return $this->data;
}
```

---

### **Task 3.3: Add Constants for Magic Numbers**
**Priority:** 🟢 Low
**Estimated Time:** 30 minutes

```php
// src/CacheDependencyManager.php

class CacheDependencyManager implements CacheDependencyInterface
{
    /**
     * Default TTL for tag version counters (30 days).
     */
    private const DEFAULT_TAG_VERSION_TTL = 86400 * 30;

    /**
     * Default cache key prefix.
     */
    private const DEFAULT_PREFIX = 'cdep';

    protected function getTagVersionTtl(): int
    {
        return config('cache-dependency.tag_version_ttl', self::DEFAULT_TAG_VERSION_TTL);
    }
}
```

---

### **Task 3.4: Update Documentation**
**Priority:** 🟡 Medium
**Estimated Time:** 2 hours

#### Files to Update
1. `README.md` - Add extensibility section
2. `ARCHITECTURE_REVIEW.md` - Mark issues as resolved
3. Create `EXTENDING.md` - Guide for custom dependencies

#### EXTENDING.md Example

```markdown
# Extending with Custom Dependencies

## Creating a Custom Dependency

All dependencies must implement `DependencyInterface`:

\`\`\`php
use Lunzai\CacheDependency\Contracts\DependencyInterface;
use Lunzai\CacheDependency\CacheDependencyManager;

class FileDependency implements DependencyInterface
{
    public function __construct(protected string $filepath) {}

    public function captureBaseline(CacheDependencyManager $manager): mixed
    {
        // Capture current state (e.g., file modification time)
        return file_exists($this->filepath)
            ? filemtime($this->filepath)
            : null;
    }

    public function isStale(CacheDependencyManager $manager, mixed $baseline = null): bool
    {
        // Check if state changed
        $current = file_exists($this->filepath)
            ? filemtime($this->filepath)
            : null;

        return $current !== $baseline;
    }

    public function toArray(): array
    {
        return ['type' => 'file', 'filepath' => $this->filepath];
    }

    public function __serialize(): array
    {
        return ['filepath' => $this->filepath];
    }

    public function __unserialize(array $data): void
    {
        $this->filepath = $data['filepath'];
    }
}
\`\`\`

## Using Custom Dependencies

\`\`\`php
use Lunzai\CacheDependency\PendingDependency;

$manager = app(\Lunzai\CacheDependency\CacheDependencyManager::class);
$pending = new PendingDependency($manager, [
    new FileDependency('/path/to/config.json')
]);

$pending->put('config', $configData, 3600);
\`\`\`
```

---

## 📊 Phase 4: Release (Day 5)

### **Task 4.1: Update Changelog**
**Priority:** 🟡 Medium
**Estimated Time:** 30 minutes

Create `CHANGELOG.md`:

```markdown
# Changelog

## [2.0.0] - 2025-01-XX

### 🚀 Major Refactoring - Improved Extensibility

#### Breaking Changes
- `CacheEntryWrapper` now stores polymorphic dependency array instead of individual properties
- `DependencyInterface::captureBaseline()` added (required)
- `DependencyInterface::isStale()` signature changed to accept `$baseline` parameter

#### Added
- ✅ Fully extensible architecture - add custom dependencies without modifying core
- ✅ `CacheEntryWrapper::getDependencies()` for inspection
- ✅ Race condition protection with cache locks
- ✅ Comprehensive error handling with configurable fail modes
- ✅ `allow_baseline_failure` config option
- ✅ `log_failures` config option

#### Fixed
- ✅ `DbDependency::isStale()` now works correctly (was always returning false)
- ✅ Tag version increment race condition
- ✅ Missing error handling in baseline capture

#### Improved
- ✅ Consistent error handling throughout
- ✅ Better logging for debugging
- ✅ Documentation for extending with custom dependencies

### Migration Guide

**Before (v1.x):**
```php
// Limited to built-in dependencies
CacheDependency::tags('users')->put('key', $value);
```

**After (v2.0):**
```php
// Same API for built-in dependencies
CacheDependency::tags('users')->put('key', $value);

// NEW: Can add custom dependencies
$manager = app(\Lunzai\CacheDependency\CacheDependencyManager::class);
$pending = new PendingDependency($manager, [
    new YourCustomDependency()
]);
$pending->put('key', $value);
```

**Internal Changes:**
If you were extending `CacheEntryWrapper` or directly instantiating it, update to new constructor signature.
```

---

### **Task 4.2: Version Bump**
**Priority:** 🔴 Critical
**Estimated Time:** 15 minutes

Update `composer.json`:
```json
{
    "version": "2.0.0"
}
```

---

### **Task 4.3: Create Git Tag & Release**
**Priority:** 🔴 Critical
**Estimated Time:** 30 minutes

```bash
# Commit all changes
git add -A
git commit -m "v2.0.0: Major refactoring for extensibility

- Refactor to polymorphic dependency architecture
- Fix DbDependency::isStale() implementation
- Add race condition protection
- Improve error handling
- Add support for custom dependencies

BREAKING CHANGES:
- CacheEntryWrapper constructor signature changed
- DependencyInterface method signatures changed
- See CHANGELOG.md for migration guide"

# Run all tests
vendor/bin/phpunit

# Create tag
git tag -a v2.0.0 -m "Version 2.0.0 - Extensible Architecture"
git push origin main --tags
```

---

## ✅ Checklist Before Release

### Code Quality
- [ ] All 20 existing tests pass
- [ ] New extensibility tests added and passing
- [ ] Code style validated with Pint
- [ ] No PHPStan/Psalm errors

### Documentation
- [ ] README.md updated
- [ ] CHANGELOG.md created
- [ ] EXTENDING.md created
- [ ] Inline documentation updated

### Testing
- [ ] Manual testing with file cache driver
- [ ] Manual testing with Redis cache driver
- [ ] Manual testing with array cache driver
- [ ] Serialization/deserialization verified
- [ ] Error scenarios tested

### Configuration
- [ ] New config options documented
- [ ] Environment variables documented
- [ ] Default values appropriate

### Backward Compatibility
- [ ] Public API unchanged (tags, db, get, put, etc.)
- [ ] Breaking changes documented in CHANGELOG
- [ ] Migration guide provided

---

## 🎯 Success Metrics

### Pre-Refactoring
- ❌ Cannot add dependency without modifying 5-7 files
- ❌ DbDependency::isStale() broken
- ❌ Race condition in tag invalidation
- ⚠️ No error handling for baseline capture

### Post-Refactoring
- ✅ Can add dependency with 1 new file
- ✅ DbDependency::isStale() works correctly
- ✅ Race condition fixed with locks
- ✅ Comprehensive error handling
- ✅ All tests pass
- ✅ Extensibility proven with test

---

## 📅 Timeline

| Phase | Days | Tasks | Risk |
|-------|------|-------|------|
| **Phase 1: Critical Architecture** | 1-2 | Refactor interfaces, CacheEntryWrapper, dependencies | High |
| **Phase 2: Testing** | 3 | Update tests, add extensibility tests | Medium |
| **Phase 3: Polish** | 4 | Fix race condition, naming, docs | Low |
| **Phase 4: Release** | 5 | Changelog, version, tag | Low |

**Total Estimated Time:** 20-30 development hours over 5 days

---

## 🚨 Risk Mitigation

### High-Risk Areas
1. **CacheEntryWrapper refactor** - Core logic change
   - Mitigation: Comprehensive testing, keep tests passing incrementally

2. **Serialization changes** - Could break existing cached entries
   - Mitigation: Add backward-compatible deserialization, cache migration guide

3. **Breaking changes** - v2.0 breaks internal APIs
   - Mitigation: Semantic versioning, clear migration guide, major version bump

### Rollback Plan
- Keep v1.x branch maintained
- Tag v1.x.x for critical fixes
- Provide clear upgrade path in docs

---

**Plan Created By:** Lead Engineer
**Date:** 2025-01-26
**Target Release:** v2.0.0
