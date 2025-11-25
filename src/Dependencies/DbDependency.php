<?php

declare(strict_types=1);

namespace Lunzai\CacheDependency\Dependencies;

use Illuminate\Support\Facades\DB;
use Lunzai\CacheDependency\CacheDependencyManager;
use Lunzai\CacheDependency\Contracts\DependencyInterface;
use Lunzai\CacheDependency\Exceptions\DatabaseDependencyException;

/**
 * Database dependency for cache invalidation.
 *
 * Executes a SQL query and compares the result to detect when
 * underlying data has changed, automatically invalidating the cache.
 */
class DbDependency implements DependencyInterface
{
    /**
     * Create a new database dependency.
     *
     * @param  string  $sql  SQL query to execute
     * @param  array<mixed>  $params  Query parameters
     * @param  string|null  $connection  Database connection name
     */
    public function __construct(
        protected string $sql,
        protected array $params = [],
        protected ?string $connection = null
    ) {}

    /**
     * Get the current value from the database query.
     *
     *
     * @throws DatabaseDependencyException
     */
    public function getCurrentValue(): mixed
    {
        try {
            $timeout = config('cache-dependency.db.timeout', 5);
            $connection = $this->connection ?? config('cache-dependency.db.connection');

            // Execute query with timeout
            $result = DB::connection($connection)
                ->select($this->sql, $this->params);

            // Return the first column of the first row (scalar value)
            if (empty($result)) {
                return null;
            }

            $firstRow = (array) $result[0];

            return reset($firstRow);
        } catch (\Exception $e) {
            throw new DatabaseDependencyException(
                "Database dependency query failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Check if this dependency is stale (required by DependencyInterface).
     *
     * Note: This method is not used directly for DB dependencies.
     * The CacheEntryWrapper calls getCurrentValue() instead.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager
     */
    public function isStale(CacheDependencyManager $manager): bool
    {
        // This method exists to satisfy the interface but is not used
        // CacheEntryWrapper handles DB dependency checking directly
        return false;
    }

    /**
     * Set the database connection for this dependency.
     *
     * @param  string  $connection  Connection name
     */
    public function setConnection(string $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Convert the dependency to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'db',
            'sql' => $this->sql,
            'params' => $this->params,
            'connection' => $this->connection,
        ];
    }

    /**
     * Serialize the dependency.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'sql' => $this->sql,
            'params' => $this->params,
            'connection' => $this->connection,
        ];
    }

    /**
     * Unserialize the dependency.
     *
     * @param  array<string, mixed>  $data  Serialized data
     */
    public function __unserialize(array $data): void
    {
        $this->sql = $data['sql'];
        $this->params = $data['params'];
        $this->connection = $data['connection'];
    }
}
