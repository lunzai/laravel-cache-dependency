<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Lunzai\CacheDependency\Facades\CacheDependency;
use Lunzai\CacheDependency\Tests\TestCase;

class CombinedDependencyTest extends TestCase
{
    public function test_tags_and_db_dependency_both_checked(): void
    {
        DB::table('users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cache with both tag and DB dependency
        CacheDependency::tags('users')
            ->db('SELECT MAX(updated_at) FROM users')
            ->put('all.users', ['John Doe'], 3600);

        $this->assertEquals(['John Doe'], CacheDependency::get('all.users'));
    }

    public function test_invalidates_when_tag_invalidated(): void
    {
        DB::table('users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CacheDependency::tags('users')
            ->db('SELECT MAX(updated_at) FROM users')
            ->put('all.users', ['John Doe'], 3600);

        // Invalidate via tag
        CacheDependency::invalidateTags('users');

        $this->assertNull(CacheDependency::get('all.users'));
    }

    public function test_invalidates_when_db_changes(): void
    {
        DB::table('users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CacheDependency::tags('users')
            ->db('SELECT MAX(updated_at) FROM users')
            ->put('all.users', ['John Doe'], 3600);

        // Add new user (changes MAX(updated_at))
        DB::table('users')->insert([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);

        $this->assertNull(CacheDependency::get('all.users'));
    }

    public function test_stays_valid_when_neither_changes(): void
    {
        DB::table('users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CacheDependency::tags('users')
            ->db('SELECT MAX(updated_at) FROM users')
            ->put('all.users', ['John Doe'], 3600);

        // First check - should hit
        $this->assertEquals(['John Doe'], CacheDependency::get('all.users'));

        // Invalidate unrelated tag
        CacheDependency::invalidateTags('roles');

        // Should still hit
        $this->assertEquals(['John Doe'], CacheDependency::get('all.users'));
    }

    public function test_real_world_rbac_scenario(): void
    {
        // Create user
        DB::table('users')->insert([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = DB::getPdo()->lastInsertId();

        // Create roles
        DB::table('roles')->insert([
            ['name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Editor', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Assign role to user
        DB::table('role_user')->insert([
            'user_id' => $userId,
            'role_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cache user permissions with both tag and DB dependency
        $permissions = ['users.view', 'users.create', 'users.edit', 'users.delete'];

        CacheDependency::tags(["user.{$userId}", 'rbac'])
            ->db('SELECT MAX(updated_at) FROM role_user WHERE user_id = ?', [$userId])
            ->put("user.{$userId}.permissions", $permissions, 3600);

        // Should hit cache
        $this->assertEquals($permissions, CacheDependency::get("user.{$userId}.permissions"));

        // Scenario 1: User's roles change (DB update)
        DB::table('role_user')->where('user_id', $userId)->update([
            'updated_at' => now()->addSecond(),
        ]);

        $this->assertNull(CacheDependency::get("user.{$userId}.permissions"));

        // Re-cache
        CacheDependency::tags(["user.{$userId}", 'rbac'])
            ->db('SELECT MAX(updated_at) FROM role_user WHERE user_id = ?', [$userId])
            ->put("user.{$userId}.permissions", $permissions, 3600);

        // Scenario 2: Permission logic changes (tag invalidation)
        CacheDependency::invalidateTags('rbac');

        $this->assertNull(CacheDependency::get("user.{$userId}.permissions"));
    }
}
