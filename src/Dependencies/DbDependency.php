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
     * Capture the current database value as baseline.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager
     * @return mixed The current database value
     *
     * @throws DatabaseDependencyException
     */
    public function captureBaseline(CacheDependencyManager $manager): mixed
    {
        return $this->fetchCurrentValue();
    }

    /**
     * Check if database value has changed from baseline.
     *
     * @param  CacheDependencyManager  $manager  The cache dependency manager
     * @param  mixed  $baseline  The baseline value captured at cache time
     * @return bool True if the current value differs from baseline
     *
     * @throws DatabaseDependencyException
     */
    public function isStale(CacheDependencyManager $manager, mixed $baseline = null): bool
    {
        $currentValue = $this->fetchCurrentValue();

        return $currentValue !== $baseline;
    }

    /**
     * Fetch the current value from the database.
     *
     * @return mixed The first column of the first row
     *
     * @throws DatabaseDependencyException
     */
    protected function fetchCurrentValue(): mixed
    {
        try {
            $connection = $this->connection ?? config('cache-dependency.db.connection');

            // Execute query
            $result = DB::connection($connection)
                ->select($this->sql, $this->params);

            // Return the first column of the first row (scalar value)
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

    /**
     * Get the current value from the database query.
     *
     * @deprecated Use captureBaseline() instead
     *
     * @throws DatabaseDependencyException
     */
    public function getCurrentValue(): mixed
    {
        return $this->fetchCurrentValue();
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
