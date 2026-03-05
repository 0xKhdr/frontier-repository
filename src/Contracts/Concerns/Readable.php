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
 * ## Naming Convention
 *
 * | Pattern | Meaning |
 * |---------|---------|
 * | `find($id)` | Single record by primary key |
 * | `findBy($conditions)` | Single record by arbitrary conditions |
 * | `findMany($ids)` | Multiple records by primary keys |
 * | `findByOr($groups)` | Single record, OR-chained condition groups |
 * | `get(...)` | Eager collection of all records |
 * | `getBy(...)` | Collection filtered by conditions |
 * | `getByOr(...)` | Collection filtered with OR logic |
 * | `paginate(...)` | LengthAwarePaginator (2 queries — total + data) |
 * | `paginateBy(...)` | LengthAwarePaginator filtered by conditions |
 * | `simplePaginate(...)` | Simple next/prev paginator (1 query) |
 * | `cursorPaginate(...)` | Cursor-based paginator (O(1), large datasets) |
 * | `*OrFail` suffix | Same as base method but throws ModelNotFoundException |
 */
interface Readable
{
    /*
    |--------------------------------------------------------------------------
    | Single Record — by Primary Key
    |--------------------------------------------------------------------------
    */

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
     * $user = $repository->find(1);
     * $user = $repository->find('uuid-string', ['id', 'name', 'email']);
     * ```
     */
    public function find(int|string $id, array $columns = ['*']): ?Model;

    /**
     * Find a record by its primary key or throw exception.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<int, string>  $columns  Columns to select
     *
     * @throws ModelNotFoundException When no record matches the ID
     *
     * @example
     * ```php
     * $user = $repository->findOrFail(1);
     * // Throws ModelNotFoundException if ID 1 doesn't exist
     * ```
     */
    public function findOrFail(int|string $id, array $columns = ['*']): Model;

    /**
     * Find multiple records by their primary keys.
     *
     * Returns only found records — missing IDs are silently omitted.
     * Use findManyOrFail() if you need strict existence checking.
     * Result order follows the database's natural ordering, not the input array order.
     *
     * @param  array<int, int|string>  $ids  Primary key values to look up
     * @param  array<int, string>  $columns  Columns to select
     * @return Collection<int, Model>
     *
     * @example
     * ```php
     * $users = $repository->findMany([1, 2, 3]);
     * ```
     */
    public function findMany(array $ids, array $columns = ['*']): Collection;

    /**
     * Find multiple records by their primary keys or throw if any are missing.
     *
     * @param  array<int, int|string>  $ids  Primary key values to look up
     * @param  array<int, string>  $columns  Columns to select
     * @return Collection<int, Model>
     *
     * @throws ModelNotFoundException When one or more IDs are not found
     *
     * @example
     * ```php
     * $users = $repository->findManyOrFail([1, 2, 3]);
     * // Throws ModelNotFoundException if any of the IDs are missing
     * ```
     */
    public function findManyOrFail(array $ids, array $columns = ['*']): Collection;

    /*
    |--------------------------------------------------------------------------
    | Single Record — by Conditions
    |--------------------------------------------------------------------------
    */

    /**
     * Find a single record by conditions.
     *
     * Returns the first record matching all conditions (AND logic), or null.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     * @return Model|null
     *
     * @example
     * ```php
     * $user = $repository->findBy(['email' => 'john@example.com']);
     * $user = $repository->findBy(['status' => 'active', 'role' => 'admin']);
     * ```
     */
    public function findBy(array $conditions, array $columns = ['*']): ?Model;

    /**
     * Find a single record by conditions or throw exception.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     *
     * @throws ModelNotFoundException When no record matches the conditions
     *
     * @example
     * ```php
     * $user = $repository->findByOrFail(['email' => 'john@example.com']);
     * ```
     */
    public function findByOrFail(array $conditions, array $columns = ['*']): Model;

    /**
     * Find a single record matching any of the provided condition groups (OR logic).
     *
     * Each condition group is AND-chained internally; groups are OR-chained together:
     * findByOr([['a' => 1], ['b' => 2]]) → WHERE (a = 1) OR (b = 2)
     *
     * @param  array<int, array<string, mixed>>  $conditionGroups
     * @param  array<int, string>  $columns
     *
     * @example
     * ```php
     * // WHERE (email = 'a@b.com') OR (username = 'johndoe')
     * $user = $repository->findByOr([
     *     ['email' => 'a@b.com'],
     *     ['username' => 'johndoe'],
     * ]);
     * ```
     */
    public function findByOr(array $conditionGroups, array $columns = ['*']): ?Model;

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    */

    /**
     * Get all records.
     *
     * Returns all records from the table. Use with caution on large tables —
     * consider using pagination methods for large datasets.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options (sorting, relations, etc.)
     * @return Collection<int, Model>
     *
     * @example
     * ```php
     * $users = $repository->get();
     * $users = $repository->get(['id', 'name'], ['sort' => 'name']);
     * ```
     */
    public function get(array $columns = ['*'], array $options = []): Collection;

    /**
     * Get records matching conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     * @return Collection<int, Model>
     *
     * @example
     * ```php
     * $activeUsers = $repository->getBy(['status' => 'active']);
     * $admins = $repository->getBy(['role' => 'admin'], ['id', 'name'], ['sort' => 'name']);
     * ```
     */
    public function getBy(array $conditions, array $columns = ['*'], array $options = []): Collection;

    /**
     * Get records matching any of the provided condition groups (OR logic).
     *
     * Each condition group is AND-chained internally; groups are OR-chained together.
     *
     * @param  array<int, array<string, mixed>>  $conditionGroups
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $options
     * @return Collection<int, Model>
     *
     * @example
     * ```php
     * // WHERE (status = 'active') OR (role = 'admin')
     * $users = $repository->getByOr([
     *     ['status' => 'active'],
     *     ['role' => 'admin'],
     * ]);
     * ```
     */
    public function getByOr(array $conditionGroups, array $columns = ['*'], array $options = []): Collection;

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    /**
     * Paginate with total count (for UI with page numbers).
     *
     * Uses 2 queries: COUNT(*) + data fetch.
     * Performance: O(n) where n = total records (for counting).
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     * @param  int|null  $perPage  Items per page (defaults to model's perPage)
     * @param  int|null  $page  Page number
     *
     * @example
     * ```php
     * $users = $repository->paginate(columns: ['id', 'name'], perPage: 15, page: 2);
     * // $users->total(), $users->lastPage(), etc.
     * ```
     */
    public function paginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): LengthAwarePaginator;

    /**
     * Paginate records matching conditions with total count.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     * @param  int|null  $perPage  Items per page
     * @param  int|null  $page  Page number
     */
    public function paginateBy(
        array $conditions,
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): LengthAwarePaginator;

    /**
     * Simple pagination without total count (for "Next/Prev" UI).
     *
     * Uses 1 query only — faster than paginate().
     * Performance: O(1) — constant time regardless of total records.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     * @param  int|null  $perPage  Items per page
     * @param  int|null  $page  Page number
     *
     * @example
     * ```php
     * $users = $repository->simplePaginate(perPage: 15);
     * // $users->hasMorePages(), but NO $users->total()
     * ```
     */
    public function simplePaginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): Paginator;

    /**
     * Cursor-based pagination for large datasets (100k+ rows).
     *
     * O(1) performance — no offset scanning, constant speed for any position.
     * Best for: infinite scroll, API endpoints, mobile apps.
     *
     * NOTE: Requires a consistent ORDER BY column (usually 'id' or 'created_at').
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     * @param  int|null  $perPage  Items per page
     * @param  string|null  $cursor  Encoded cursor from previous page
     *
     * @example
     * ```php
     * $users = $repository->cursorPaginate(perPage: 50);
     * $nextCursor = $users->nextCursor()?->encode();
     * ```
     */
    public function cursorPaginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?string $cursor = null
    ): CursorPaginator;

    /*
    |--------------------------------------------------------------------------
    | Aggregation
    |--------------------------------------------------------------------------
    */

    /**
     * Count records matching conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions (empty = count all)
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
