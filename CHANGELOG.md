# Changelog

All notable changes to `laravel-cache-dependency` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-01-25

### Added
- Tag dependencies with O(1) invalidation via version counters
- Database dependencies with automatic staleness detection
- Combined tag + database dependencies
- Works with all Laravel cache drivers (file, array, database, Redis, Memcached)
- Configurable fail-open/fail-closed behavior for database query failures
- Full Laravel Cache facade interoperability
- Fluent API with PendingDependency for chaining operations
- `CacheDependency` facade for easy usage
- Comprehensive test suite with 100% passing tests
- Support for Laravel 11+ and PHP 8.2+

### Features
- **CacheDependency::tags()** - Create cache entries with tag dependencies
- **CacheDependency::db()** - Create cache entries with database query dependencies
- **CacheDependency::invalidateTags()** - O(1) tag invalidation
- **CacheDependency::get()** - Retrieve cache with automatic staleness checking
- **CacheDependency::remember()** - Remember pattern with dependencies
- **CacheDependency::forever()** - Store indefinitely with dependencies
- **CacheDependency::putMany()** - Store multiple items with dependencies

### Configuration
- Configurable cache store
- Configurable key prefix for internal storage
- Configurable tag version TTL (default 30 days)
- Configurable database connection, timeout, and failure behavior

[Unreleased]: https://github.com/lunzai/laravel-cache-dependency/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/lunzai/laravel-cache-dependency/releases/tag/v1.0.0
