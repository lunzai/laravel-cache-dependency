<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Lunzai\CacheDependency\Facades\CacheDependency;
use Lunzai\CacheDependency\Tests\TestCase;

class DbDependencyIntegrationTest extends TestCase
{
    public function test_cache_invalidates_when_db_query_result_changes(): void
    {
        // Create initial user
        DB::table('users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::getPdo()->lastInsertId();

        // Cache with DB dependency
        CacheDependency::db('SELECT MAX(updated_at) FROM users WHERE id = ?', [$userId])
            ->put("user.{$userId}", ['name' => 'John Doe'], 3600);

        // Cache should hit
        $this->assertEquals(['name' => 'John Doe'], CacheDependency::get("user.{$userId}"));

        // Update the user (changes updated_at)
        DB::table('users')->where('id', $userId)->update([
            'name' => 'John Updated',
            'updated_at' => now()->addSecond(),
        ]);

        // Cache should miss (stale due to updated_at change)
        $this->assertNull(CacheDependency::get("user.{$userId}"));
    }

    public function test_cache_stays_valid_when_db_result_unchanged(): void
    {
        DB::table('users')->insert([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::getPdo()->lastInsertId();

        CacheDependency::db('SELECT MAX(updated_at) FROM users WHERE id = ?', [$userId])
            ->put("user.{$userId}", ['name' => 'Jane Doe'], 3600);

        $this->assertEquals(['name' => 'Jane Doe'], CacheDependency::get("user.{$userId}"));

        // Update a different user (should not affect this cache)
        DB::table('users')->insert([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cache should still hit
        $this->assertEquals(['name' => 'Jane Doe'], CacheDependency::get("user.{$userId}"));
    }

    public function test_db_dependency_with_count_query(): void
    {
        // Cache count of users
        CacheDependency::db('SELECT COUNT(*) FROM users')
            ->put('user.count', 0, 3600);

        $this->assertEquals(0, CacheDependency::get('user.count'));

        // Add a user
        DB::table('users')->insert([
            'name' => 'New User',
            'email' => 'new@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cache should be stale
        $this->assertNull(CacheDependency::get('user.count'));
    }

    public function test_remember_with_db_dependency(): void
    {
        DB::table('roles')->insert([
            'name' => 'Admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callbackExecuted = false;

        $result = CacheDependency::db('SELECT MAX(updated_at) FROM roles')
            ->remember('all.roles', 3600, function () use (&$callbackExecuted) {
                $callbackExecuted = true;

                return DB::table('roles')->get()->toArray();
            });

        $this->assertTrue($callbackExecuted);
        $this->assertCount(1, $result);

        // Second call should not execute callback
        $callbackExecuted = false;

        $result = CacheDependency::db('SELECT MAX(updated_at) FROM roles')
            ->remember('all.roles', 3600, function () use (&$callbackExecuted) {
                $callbackExecuted = true;

                return DB::table('roles')->get()->toArray();
            });

        $this->assertFalse($callbackExecuted);
        $this->assertCount(1, $result);
    }

    public function test_db_dependency_with_null_result(): void
    {
        // Query that returns null (no records)
        CacheDependency::db('SELECT MAX(updated_at) FROM users')
            ->put('user.latest', ['none' => true], 3600);

        $this->assertEquals(['none' => true], CacheDependency::get('user.latest'));

        // Add a user
        DB::table('users')->insert([
            'name' => 'First User',
            'email' => 'first@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cache should be stale (null changed to a timestamp)
        $this->assertNull(CacheDependency::get('user.latest'));
    }
}
