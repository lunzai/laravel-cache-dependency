<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Lunzai\CacheDependency\Facades\CacheDependency;
use Lunzai\CacheDependency\Tests\TestCase;

class TagInvalidationTest extends TestCase
{
    public function test_cache_with_single_tag_invalidates(): void
    {
        CacheDependency::tags('users')->put('user.1', ['name' => 'John'], 3600);

        $this->assertEquals(['name' => 'John'], CacheDependency::get('user.1'));

        CacheDependency::invalidateTags('users');

        $this->assertNull(CacheDependency::get('user.1'));
    }

    public function test_cache_with_multiple_tags_invalidates_when_any_tag_invalidated(): void
    {
        CacheDependency::tags(['users', 'permissions'])->put('user.1.permissions', ['read', 'write'], 3600);

        $this->assertEquals(['read', 'write'], CacheDependency::get('user.1.permissions'));

        CacheDependency::invalidateTags('permissions');

        $this->assertNull(CacheDependency::get('user.1.permissions'));
    }

    public function test_cache_stays_valid_when_unrelated_tag_invalidated(): void
    {
        CacheDependency::tags(['users'])->put('user.1', ['name' => 'John'], 3600);

        $this->assertEquals(['name' => 'John'], CacheDependency::get('user.1'));

        CacheDependency::invalidateTags('roles');

        $this->assertEquals(['name' => 'John'], CacheDependency::get('user.1'));
    }

    public function test_retrieval_without_tags_works(): void
    {
        CacheDependency::tags(['users', 'permissions'])->put('user.1', ['name' => 'John'], 3600);

        // Can retrieve without specifying tags
        $this->assertEquals(['name' => 'John'], CacheDependency::get('user.1'));
    }

    public function test_cache_facade_retrieves_wrapped_values(): void
    {
        CacheDependency::tags('users')->put('user.1', ['name' => 'John'], 3600);

        // Standard Cache facade can also retrieve (gets unwrapped value)
        $result = Cache::get('user.1');

        // Cache facade will get the wrapper, but CacheDependency unwraps it
        $this->assertNotNull($result);
    }

    public function test_tag_version_increments_correctly(): void
    {
        $initialVersion = CacheDependency::getTagVersion('users');

        CacheDependency::invalidateTags('users');

        $newVersion = CacheDependency::getTagVersion('users');

        $this->assertEquals($initialVersion + 1, $newVersion);
    }

    public function test_multiple_caches_sharing_tag_all_invalidate(): void
    {
        CacheDependency::tags('users')->put('user.1', ['name' => 'John'], 3600);
        CacheDependency::tags('users')->put('user.2', ['name' => 'Jane'], 3600);
        CacheDependency::tags('roles')->put('role.1', ['name' => 'Admin'], 3600);

        CacheDependency::invalidateTags('users');

        $this->assertNull(CacheDependency::get('user.1'));
        $this->assertNull(CacheDependency::get('user.2'));
        $this->assertEquals(['name' => 'Admin'], CacheDependency::get('role.1'));
    }

    public function test_remember_with_tags_works(): void
    {
        $callbackExecuted = false;

        $result = CacheDependency::tags('users')->remember('user.1', 3600, function () use (&$callbackExecuted) {
            $callbackExecuted = true;

            return ['name' => 'John'];
        });

        $this->assertTrue($callbackExecuted);
        $this->assertEquals(['name' => 'John'], $result);

        // Second call should return cached value
        $callbackExecuted = false;

        $result = CacheDependency::tags('users')->remember('user.1', 3600, function () use (&$callbackExecuted) {
            $callbackExecuted = true;

            return ['name' => 'John'];
        });

        $this->assertFalse($callbackExecuted);
        $this->assertEquals(['name' => 'John'], $result);

        // After invalidation, callback should execute again
        CacheDependency::invalidateTags('users');

        $callbackExecuted = false;

        $result = CacheDependency::tags('users')->remember('user.1', 3600, function () use (&$callbackExecuted) {
            $callbackExecuted = true;

            return ['name' => 'John Updated'];
        });

        $this->assertTrue($callbackExecuted);
        $this->assertEquals(['name' => 'John Updated'], $result);
    }

    public function test_forever_with_tags_works(): void
    {
        CacheDependency::tags('config')->forever('app.settings', ['theme' => 'dark']);

        $this->assertEquals(['theme' => 'dark'], CacheDependency::get('app.settings'));

        CacheDependency::invalidateTags('config');

        $this->assertNull(CacheDependency::get('app.settings'));
    }

    public function test_invalidate_multiple_tags_at_once(): void
    {
        CacheDependency::tags('users')->put('user.1', ['name' => 'John'], 3600);
        CacheDependency::tags('roles')->put('role.1', ['name' => 'Admin'], 3600);

        CacheDependency::invalidateTags(['users', 'roles']);

        $this->assertNull(CacheDependency::get('user.1'));
        $this->assertNull(CacheDependency::get('role.1'));
    }
}
