<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Laravel Cache Dependency package.
 */
class CacheDependencyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/cache-dependency.php',
            'cache-dependency'
        );

        $this->app->singleton('cache.dependency', function ($app) {
            $store = config('cache-dependency.store');

            return new CacheDependencyManager($store);
        });

        $this->app->alias('cache.dependency', CacheDependencyManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cache-dependency.php' => config_path('cache-dependency.php'),
            ], 'cache-dependency-config');
        }
    }
}
