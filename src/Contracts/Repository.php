<?php

declare(strict_types=1);

namespace Frontier\Repositories\Contracts;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Contract for Eloquent-based repository implementations.
 */
interface Repository
{
    /**
     * Create a new model record.
     *
     * @param  array<string, mixed>  $values  The attributes to create
     */
    public function create(array $values): Model;

    /**
     * Update records matching conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<string, mixed>  $values  Values to update
     * @return int Number of affected rows
     */
    public function update(array $conditions, array $values): int;

    /**
     * Delete records matching conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return int Number of deleted rows
     */
    public function delete(array $conditions): int;

    /**
     * Insert multiple records.
     *
     * @param  array<int|string, mixed>  $values  Records to insert
     */
    public function insert(array $values): bool;

    /**
     * Insert and get the new ID.
     *
     * @param  array<string, mixed>  $values  Values to insert
     */
    public function insertGetId(array $values): int;

    /**
     * Insert or update multiple records.
     *
     * @param  array<int, array<string, mixed>>  $values  Records to upsert
     * @param  array<int, string>  $uniqueBy  Unique columns
     * @param  array<int, string>|null  $update  Columns to update
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): int;

    /**
     * Retrieve all records.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    public function retrieve(array $columns = ['*'], array $options = []): Collection;

    /**
     * Retrieve records by conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    public function retrieveBy(array $conditions, array $columns = ['*'], array $options = []): Collection;

    /**
     * Retrieve paginated results with total count.
     * Uses 2 queries: COUNT(*) + data fetch.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    public function retrievePaginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): LengthAwarePaginator;

    /**
     * Simple paginate without total count (for "Next/Prev" UI).
     * Uses 1 query only - faster than retrievePaginate().
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    public function retrieveSimplePaginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): Paginator;

    /**
     * Cursor-based pagination for large datasets.
     * O(1) performance - no offset scanning.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    public function retrieveCursorPaginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?string $cursor = null
    ): CursorPaginator;

    /**
     * Find a single record.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     */
    public function find(array $conditions, array $columns = ['*']): ?Model;

    /**
     * Find a record or throw exception.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(array $conditions, array $columns = ['*']): Model;

    /**
     * Update or create a record.
     *
     * @param  array<string, mixed>  $conditions  Attributes to match
     * @param  array<string, mixed>  $values  Values to update/create
     */
    public function updateOrCreate(array $conditions, array $values): Model;

    /**
     * Find or create a record.
     *
     * @param  array<string, mixed>  $conditions  Attributes to match
     * @param  array<string, mixed>  $values  Additional creation values
     */
    public function firstOrCreate(array $conditions, array $values = []): Model;

    /**
     * Count matching records.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     */
    public function count(array $conditions = []): int;

    /**
     * Check if records exist.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     */
    public function exists(array $conditions): bool;

    /**
     * Process records in chunks.
     */
    public function chunk(int $count, callable $callback): bool;

    /**
     * Execute within a transaction.
     *
     * @throws Throwable
     */
    public function transaction(callable $callback): mixed;

    /**
     * Set a base query builder.
     */
    public function withBuilder(Builder $builder): self;

    /**
     * Reset the query builder.
     */
    public function resetBuilder(): self;

    /**
     * Get the table name.
     */
    public function getTable(): string;

    /**
     * Get the model instance.
     */
    public function getModel(): Model;

    /**
     * Get the query builder.
     */
    public function getBuilder(): Builder;
}
