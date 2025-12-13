<?php

declare(strict_types=1);

namespace Frontier\Repositories;

use Frontier\Repositories\Contracts\Repository as RepositoryContract;
use Frontier\Repositories\Traits\Retrievable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Eloquent-based repository implementation.
 *
 * Provides a fluent API for database operations with query builder chaining.
 */
abstract class BaseRepository implements RepositoryContract
{
    use Retrievable;

    protected Builder $builder;

    protected ?Builder $withBuilder = null;

    public function __construct(Model $model)
    {
        $this->builder = $model->newQuery();
    }

    /**
     * Create a new record.
     *
     * @param  array<string, mixed>  $values  The attributes to create
     */
    public function create(array $values): Model
    {
        return tap($this->builder->create($values), function (Model $model): void {
            $this->resetBuilder();
        });
    }

    /**
     * Retrieve all records.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    public function retrieve(array $columns = ['*'], array $options = []): Collection
    {
        return $this->getRetrieveQuery($columns, $options)->get();
    }

    /**
     * Paginate with total count (for UI with page numbers).
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
    ): LengthAwarePaginator {
        $perPage ??= $this->getModel()->getPerPage();

        return $this->getRetrieveQueryForPagination($columns, $options)
            ->paginate(perPage: $perPage, page: $page);
    }

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
    ): Paginator {
        $perPage ??= $this->getModel()->getPerPage();

        return $this->getRetrieveQueryForPagination($columns, $options)
            ->simplePaginate(perPage: $perPage, page: $page);
    }

    /**
     * Cursor-based pagination for large datasets (100k+ rows).
     * O(1) performance - no offset scanning, constant speed for any "page".
     * Best for: infinite scroll, API endpoints, mobile apps.
     *
     * NOTE: Requires consistent ORDER BY column (usually 'id' or 'created_at').
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    public function retrieveCursorPaginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?string $cursor = null
    ): CursorPaginator {
        $perPage ??= $this->getModel()->getPerPage();

        return $this->getRetrieveQueryForPagination($columns, $options)
            ->cursorPaginate(perPage: $perPage, cursor: $cursor);
    }

    /**
     * Find a single record.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     */
    public function find(array $conditions, array $columns = ['*']): ?Model
    {
        return $this->select($columns)
            ->where($conditions)
            ->getBuilder()
            ->first();
    }

    /**
     * Find a record or throw exception.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     */
    public function findOrFail(array $conditions, array $columns = ['*']): Model
    {
        return $this->select($columns)
            ->where($conditions)
            ->getBuilder()
            ->firstOrFail();
    }

    /**
     * Update records matching conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<string, mixed>  $values  Values to update
     * @return int Number of affected rows
     */
    public function update(array $conditions, array $values): int
    {
        return $this->where($conditions)
            ->getBuilder()
            ->update($values);
    }

    /**
     * Update or create a record.
     *
     * @param  array<string, mixed>  $conditions  Attributes to match
     * @param  array<string, mixed>  $values  Values to update/create
     */
    public function updateOrCreate(array $conditions, array $values): Model
    {
        return tap($this->builder->updateOrCreate($conditions, $values), function (): void {
            $this->resetBuilder();
        });
    }

    /**
     * Delete records matching conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return int Number of deleted rows
     */
    public function delete(array $conditions): int
    {
        return $this->where($conditions)
            ->getBuilder()
            ->delete();
    }

    /**
     * Count records matching conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     */
    public function count(array $conditions = []): int
    {
        return $this->where($conditions)
            ->getBuilder()
            ->count();
    }

    /**
     * Check if records exist.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     */
    public function exists(array $conditions): bool
    {
        return $this->where($conditions)
            ->getBuilder()
            ->exists();
    }

    /**
     * Insert records without creating models.
     *
     * @param  array<int|string, mixed>  $values  Values to insert
     */
    public function insert(array $values): bool
    {
        return $this->builder->insert($values);
    }

    /**
     * Insert a record and get the ID.
     *
     * @param  array<string, mixed>  $values  Values to insert
     */
    public function insertGetId(array $values): int
    {
        return $this->builder->insertGetId($values);
    }

    /**
     * Find or create a record.
     *
     * @param  array<string, mixed>  $conditions  Attributes to match
     * @param  array<string, mixed>  $values  Additional values for creation
     */
    public function firstOrCreate(array $conditions, array $values = []): Model
    {
        return tap($this->builder->firstOrCreate($conditions, $values), function (): void {
            $this->resetBuilder();
        });
    }

    /**
     * Insert or update multiple records.
     *
     * @param  array<int, array<string, mixed>>  $values  Values to upsert
     * @param  array<int, string>  $uniqueBy  Unique columns
     * @param  array<int, string>|null  $update  Columns to update
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): int
    {
        return $this->builder->upsert($values, $uniqueBy, $update);
    }

    /**
     * Process records in chunks.
     *
     * @param  int  $count  Chunk size
     * @param  callable  $callback  Callback for each chunk
     */
    public function chunk(int $count, callable $callback): bool
    {
        return $this->builder->chunk($count, $callback);
    }

    /**
     * Execute operations within a database transaction.
     *
     * @throws Throwable
     */
    public function transaction(callable $callback): mixed
    {
        return $this->getModel()->getConnection()->transaction($callback);
    }

    /**
     * Reset the query builder.
     */
    public function resetBuilder(): static
    {
        $this->builder = $this->withBuilder instanceof \Illuminate\Contracts\Database\Eloquent\Builder
            ? $this->withBuilder->clone()
            : $this->getModel()->newQuery();

        return $this;
    }

    /**
     * Get the model's table name.
     */
    public function getTable(): string
    {
        return $this->getModel()->getTable();
    }

    /**
     * Get the underlying model.
     */
    public function getModel(): Model
    {
        return $this->builder->getModel();
    }

    /**
     * Set a base builder for queries.
     */
    public function withBuilder(Builder $builder): static
    {
        $this->withBuilder = $builder;

        return $this;
    }

    /**
     * Get the current query builder.
     */
    public function getBuilder(): Builder
    {
        return $this->builder;
    }
}
