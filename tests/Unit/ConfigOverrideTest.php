<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Tests\Unit;

use Lunzai\CacheDependency\CacheEntryWrapper;
use Lunzai\CacheDependency\Dependencies\DbDependency;
use Lunzai\CacheDependency\Dependencies\TagDependency;
use Lunzai\CacheDependency\Tests\TestCase;

class ConfigOverrideTest extends TestCase
{
    public function test_global_fail_open_overrides_db_specific_setting(): void
    {
        // Set up: DB-specific says fail closed, global says fail open
        config(['cache-dependency.db.fail_open' => false]);
        config(['cache-dependency.fail_open' => true]);

        $wrapper = new CacheEntryWrapper('test data', []);
        $reflection = new \ReflectionClass($wrapper);
        $method = $reflection->getMethod('shouldFailOpen');
        $method->setAccessible(true);

        $dbDependency = new DbDependency('SELECT 1');

        // Global (true) should override DB-specific (false)
        $result = $method->invoke($wrapper, $dbDependency);

        $this->assertTrue($result, 'Global fail_open should override db.fail_open');
    }

    public function test_db_specific_setting_used_when_global_is_null(): void
    {
        // Set up: Global is null, DB-specific says fail open
        config(['cache-dependency.fail_open' => null]);
        config(['cache-dependency.db.fail_open' => true]);

        $wrapper = new CacheEntryWrapper('test data', []);
        $reflection = new \ReflectionClass($wrapper);
        $method = $reflection->getMethod('shouldFailOpen');
        $method->setAccessible(true);

        $dbDependency = new DbDependency('SELECT 1');

        // Should use DB-specific setting
        $result = $method->invoke($wrapper, $dbDependency);

        $this->assertTrue($result, 'Should use db.fail_open when global is null');
    }

    public function test_non_db_dependency_defaults_to_fail_closed(): void
    {
        // Set up: No specific config for tag dependencies
        config(['cache-dependency.fail_open' => null]);

        $wrapper = new CacheEntryWrapper('test data', []);
        $reflection = new \ReflectionClass($wrapper);
        $method = $reflection->getMethod('shouldFailOpen');
        $method->setAccessible(true);

        $tagDependency = new TagDependency(['users']);

        // Should default to fail closed (false)
        $result = $method->invoke($wrapper, $tagDependency);

        $this->assertFalse($result, 'Non-DB dependencies should default to fail closed');
    }

    public function test_global_fail_open_applies_to_all_dependency_types(): void
    {
        // Set up: Global says fail open
        config(['cache-dependency.fail_open' => true]);

        $wrapper = new CacheEntryWrapper('test data', []);
        $reflection = new \ReflectionClass($wrapper);
        $method = $reflection->getMethod('shouldFailOpen');
        $method->setAccessible(true);

        $tagDependency = new TagDependency(['users']);
        $dbDependency = new DbDependency('SELECT 1');

        // Both should fail open
        $this->assertTrue(
            $method->invoke($wrapper, $tagDependency),
            'Global fail_open should apply to TagDependency'
        );

        $this->assertTrue(
            $method->invoke($wrapper, $dbDependency),
            'Global fail_open should apply to DbDependency'
        );
    }
}
