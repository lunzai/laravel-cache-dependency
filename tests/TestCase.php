<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunzai\CacheDependency\CacheDependencyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            CacheDependencyServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Setup default cache driver as array
        $app['config']->set('cache.default', 'array');

        // Setup database config for testing
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup cache dependency config
        $app['config']->set('cache-dependency.store', null);
        $app['config']->set('cache-dependency.prefix', 'cdep');
        $app['config']->set('cache-dependency.tag_version_ttl', 86400 * 30);
        $app['config']->set('cache-dependency.db.connection', null);
        $app['config']->set('cache-dependency.db.timeout', 5);
        $app['config']->set('cache-dependency.db.fail_open', false);
    }
}
