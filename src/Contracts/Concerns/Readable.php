<?php

declare(strict_types=1);

namespace Frontier\Repositories\Contracts\Concerns;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Contract for READ operations.
 *
 * Defines methods for retrieving records from the database.
 *
 * ## Method Categories
 *
 * ### Single Record (by conditions)
 * - `find()` / `findOrFail()` - Find first matching record
 *
 * ### Single Record (by primary key)
 * - `findById()` / `findByIdOrFail()` - Find by primary key
 *
 * ### Multiple Records
 * - `retrieve()` - Get all records
 * - `retrieveBy()` - Get records by conditions
 *
 * ### Pagination
 * - `retrievePaginate()` - Full pagination with total count (2 queries)
 * - `retrieveSimplePaginate()` - Simple next/prev pagination (1 query)
 * - `retrieveCursorPaginate()` - Cursor-based for large datasets (O(1))
 *
 * ### Aggregation
 * - `count()` - Count matching records
 * - `exists()` - Check if records exist
 */
interface Readable
{
    /*
    |--------------------------------------------------------------------------
    | Single Record Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Find a single record by conditions.
     *
     * Returns the first record matching all conditions, or null if not found.
     *
     * @param  array<string, mixed>  $conditions  Where conditions (AND logic)
     * @param  array<int, string>  $columns  Columns to select
     * @return Model|null The found model or null
     *
     * @example
     * ```php
     * $user = $repository->find(['email' => 'john@example.com']);
     * $user = $repository->find(['status' => 'active', 'role' => 'admin']);
     * ```
     */
    public function find(array $conditions, array $columns = ['*']): ?Model;

    /**
     * Find a single record by conditions or throw exception.
     *
     * Same as find() but throws ModelNotFoundException if not found.
     * Useful in controllers where a 404 response is expected.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     * @return Model The found model
     *
     * @throws ModelNotFoundException When no record matches the conditions
     *
     * @example
     * ```php
     * $user = $repository->findOrFail(['email' => 'john@example.com']);
     * // Throws ModelNotFoundException if not found
     * ```
     */
    public function findOrFail(array $conditions, array $columns = ['*']): Model;

    /**
     * Find a record by its primary key.
     *
     * Optimized lookup using the model's primary key.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<int, string>  $columns  Columns to select
     * @return Model|null The found model or null
     *
     * @example
     * ```php
     * $user = $repository->findById(1);
     * $user = $repository->findById('uuid-string', ['id', 'name', 'email']);
     * ```
     */
    public function findById(int|string $id, array $columns = ['*']): ?Model;

    /**
     * Find a record by its primary key or throw exception.
     *
     * Same as findById() but throws ModelNotFoundException if not found.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<int, string>  $columns  Columns to select
     * @return Model The found model
     *
     * @throws ModelNotFoundException When no record matches the ID
     *
     * @example
     * ```php
     * $user = $repository->findByIdOrFail(1);
     * // Throws ModelNotFoundException if not found
     * ```
     */
    public function findByIdOrFail(int|string $id, array $columns = ['*']): Model;

    /*
    |--------------------------------------------------------------------------
    | Multiple Records Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Retrieve all records.
     *
     * Returns all records from the table. Use with caution on large tables.
     * Consider using pagination methods for large datasets.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options (sorting, relations, etc.)
     * @return Collection<int, Model> Collection of models (may be empty)
     *
     * @example
     * ```php
     * $users = $repository->retrieve();
     * $users = $repository->retrieve(['id', 'name'], ['sort' => 'name']);
     * ```
     */
    public function retrieve(array $columns = ['*'], array $options = []): Collection;

    /**
     * Retrieve records matching conditions.
     *
     * Returns all records matching the given conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     * @return Collection<int, Model> Collection of matching models (may be empty)
     *
     * @example
     * ```php
     * $activeUsers = $repository->retrieveBy(['status' => 'active']);
     * $admins = $repository->retrieveBy(
     *     ['role' => 'admin'],
     *     ['id', 'name'],
     *     ['sort' => '-created_at']
     * );
     * ```
     */
    public function retrieveBy(array $conditions, array $columns = ['*'], array $options = []): Collection;

    /*
    |--------------------------------------------------------------------------
    | Pagination Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Retrieve paginated results with total count.
     *
     * Returns a paginator with total count, suitable for UIs with page numbers.
     * Uses 2 queries: COUNT(*) + data fetch.
     *
     * Performance: O(n) where n = total records (for counting)
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     * @param  int|null  $perPage  Items per page (defaults to model's perPage)
     * @param  int|null  $page  Page number
     * @return LengthAwarePaginator Paginator with total count
     *
     * @example
     * ```php
     * $users = $repository->retrievePaginate(
     *     columns: ['id', 'name'],
     *     perPage: 15,
     *     page: 2
     * );
     * // $users->total(), $users->lastPage(), etc.
     * ```
     */
    public function retrievePaginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): LengthAwarePaginator;

    /**
     * Simple pagination without total count.
     *
     * Returns a simple paginator with only "Next/Previous" navigation.
     * Uses 1 query only - faster than retrievePaginate().
     *
     * Performance: O(1) - constant time regardless of total records
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     * @param  int|null  $perPage  Items per page
     * @param  int|null  $page  Page number
     * @return Paginator Simple paginator
     *
     * @example
     * ```php
     * $users = $repository->retrieveSimplePaginate(perPage: 15);
     * // $users->hasMorePages(), but NO $users->total()
     * ```
     */
    public function retrieveSimplePaginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): Paginator;

    /**
     * Cursor-based pagination for large datasets.
     *
     * Uses cursor (keyset) pagination for O(1) performance.
     * Ideal for infinite scroll, APIs, and datasets with 100k+ rows.
     *
     * Performance: O(1) - constant time regardless of "page" position
     * Note: Requires consistent ORDER BY column (usually 'id' or 'created_at')
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     * @param  int|null  $perPage  Items per page
     * @param  string|null  $cursor  Encoded cursor from previous page
     * @return CursorPaginator Cursor paginator
     *
     * @example
     * ```php
     * $users = $repository->retrieveCursorPaginate(perPage: 50);
     * $nextCursor = $users->nextCursor()?->encode();
     * // Pass cursor to next request for seamless scrolling
     * ```
     */
    public function retrieveCursorPaginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?string $cursor = null
    ): CursorPaginator;

    /*
    |--------------------------------------------------------------------------
    | Aggregation Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Count records matching conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions (empty = count all)
     * @return int Number of matching records
     *
     * @example
     * ```php
     * $total = $repository->count();
     * $activeCount = $repository->count(['status' => 'active']);
     * ```
     */
    public function count(array $conditions = []): int;

    /**
     * Check if any records exist matching conditions.
     *
     * More efficient than count() > 0 as it stops at first match.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return bool True if at least one record exists
     *
     * @example
     * ```php
     * if ($repository->exists(['email' => 'john@example.com'])) {
     *     // Email already taken
     * }
     * ```
     */
    public function exists(array $conditions): bool;
}
