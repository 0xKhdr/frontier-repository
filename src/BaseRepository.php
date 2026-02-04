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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Eloquent-based repository implementation.
 *
 * Provides a fluent API for database operations. Each method creates a fresh
 * query builder to ensure isolation and prevent state accumulation.
 */
abstract class BaseRepository implements RepositoryContract
{
    use Retrievable;

    /**
     * The Eloquent model instance.
     */
    protected Model $model;

    /**
     * Optional base builder with pre-applied scopes/filters.
     */
    protected ?Builder $withBuilder = null;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Create a fresh query builder instance.
     *
     * If a base builder was set via withBuilder(), it clones that instead.
     */
    public function newQuery(): Builder
    {
        return $this->withBuilder instanceof Builder
            ? $this->withBuilder->clone()
            : $this->model->newQuery();
    }

    /**
     * Create a new record.
     *
     * @param  array<string, mixed>  $values  The attributes to create
     */
    public function create(array $values): Model
    {
        return $this->newQuery()->create($values);
    }

    /**
     * Create multiple records using Eloquent models.
     *
     * @param  array<int, array<string, mixed>>  $records  Array of records to create
     * @return Collection<int, Model> Collection of created models
     */
    public function createMany(array $records): Collection
    {
        $models = new Collection;
        $query = $this->newQuery();

        foreach ($records as $record) {
            $models->push($query->create($record));
        }

        return $models;
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
     * Retrieve records by conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    public function retrieveBy(array $conditions, array $columns = ['*'], array $options = []): Collection
    {
        return $this->getRetrieveQuery($columns, $options)
            ->where($conditions)
            ->get();
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
        $perPage ??= $this->model->getPerPage();

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
        $perPage ??= $this->model->getPerPage();

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
        $perPage ??= $this->model->getPerPage();

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
        return $this->newQuery()
            ->select($this->prefixColumns($columns))
            ->where($conditions)
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
        return $this->newQuery()
            ->select($this->prefixColumns($columns))
            ->where($conditions)
            ->firstOrFail();
    }

    /**
     * Find a record by its primary key.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<int, string>  $columns  Columns to select
     */
    public function findById(int|string $id, array $columns = ['*']): ?Model
    {
        return $this->newQuery()
            ->select($this->prefixColumns($columns))
            ->find($id);
    }

    /**
     * Find a record by its primary key or throw exception.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<int, string>  $columns  Columns to select
     *
     * @throws ModelNotFoundException
     */
    public function findByIdOrFail(int|string $id, array $columns = ['*']): Model
    {
        return $this->newQuery()
            ->select($this->prefixColumns($columns))
            ->findOrFail($id);
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
        return $this->newQuery()
            ->where($conditions)
            ->update($values);
    }

    /**
     * Update records matching conditions or throw if none found.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<string, mixed>  $values  Values to update
     * @return int Number of affected rows
     *
     * @throws ModelNotFoundException
     */
    public function updateOrFail(array $conditions, array $values): int
    {
        $affected = $this->update($conditions, $values);

        if ($affected === 0) {
            throw (new ModelNotFoundException)->setModel(get_class($this->model));
        }

        return $affected;
    }

    /**
     * Update records matching conditions using Eloquent models.
     *
     * This method retrieves all matching records and updates each using
     * Eloquent's model-level update, ensuring that casts, mutators, accessors,
     * and model events (updating/updated) are triggered for each record.
     *
     * Note: This is slower than update() but respects model lifecycle.
     * Use update() for bulk operations where lifecycle isn't needed.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<string, mixed>  $values  Values to update
     * @return Collection<int, Model> Collection of updated models
     */
    public function updateBy(array $conditions, array $values): Collection
    {
        $models = $this->newQuery()
            ->where($conditions)
            ->get();

        foreach ($models as $model) {
            $model->update($values);
        }

        return $models;
    }

    /**
     * Update records matching conditions using Eloquent models or throw if none found.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<string, mixed>  $values  Values to update
     * @return Collection<int, Model> Collection of updated models
     *
     * @throws ModelNotFoundException
     */
    public function updateByOrFail(array $conditions, array $values): Collection
    {
        $models = $this->updateBy($conditions, $values);

        if ($models->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(get_class($this->model));
        }

        return $models;
    }

    /**
     * Update a record by its primary key.
     *
     * This method uses Eloquent's model-level update, ensuring that casts,
     * mutators, accessors, and model events (updating/updated) are triggered.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<string, mixed>  $values  Values to update
     * @return Model|null The updated model or null if not found
     */
    public function updateById(int|string $id, array $values): ?Model
    {
        $model = $this->findById($id);

        if ($model === null) {
            return null;
        }

        $model->update($values);

        return $model;
    }

    /**
     * Update a record by its primary key or throw exception.
     *
     * This method uses Eloquent's model-level update, ensuring that casts,
     * mutators, accessors, and model events (updating/updated) are triggered.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<string, mixed>  $values  Values to update
     *
     * @throws ModelNotFoundException
     */
    public function updateByIdOrFail(int|string $id, array $values): Model
    {
        $model = $this->findByIdOrFail($id);

        $model->update($values);

        return $model;
    }

    /**
     * Update or create a record.
     *
     * @param  array<string, mixed>  $conditions  Attributes to match
     * @param  array<string, mixed>  $values  Values to update/create
     */
    public function updateOrCreate(array $conditions, array $values): Model
    {
        return $this->newQuery()->updateOrCreate($conditions, $values);
    }

    /**
     * Delete records matching conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return int Number of deleted rows
     */
    public function delete(array $conditions): int
    {
        return $this->newQuery()
            ->where($conditions)
            ->delete();
    }

    /**
     * Delete records matching conditions or throw if none found.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return int Number of deleted rows
     *
     * @throws ModelNotFoundException
     */
    public function deleteOrFail(array $conditions): int
    {
        $deleted = $this->delete($conditions);

        if ($deleted === 0) {
            throw (new ModelNotFoundException)->setModel(get_class($this->model));
        }

        return $deleted;
    }

    /**
     * Delete records matching conditions using Eloquent models.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return Collection<int, Model> Collection of deleted models
     */
    public function deleteBy(array $conditions): Collection
    {
        $models = $this->newQuery()
            ->where($conditions)
            ->get();

        foreach ($models as $model) {
            $model->delete();
        }

        return $models;
    }

    /**
     * Delete records matching conditions using Eloquent models or throw if none found.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return Collection<int, Model> Collection of deleted models
     *
     * @throws ModelNotFoundException
     */
    public function deleteByOrFail(array $conditions): Collection
    {
        $models = $this->deleteBy($conditions);

        if ($models->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(get_class($this->model));
        }

        return $models;
    }

    /**
     * Delete a record by its primary key.
     *
     * This method uses Eloquent's model-level delete, ensuring that
     * model events (deleting/deleted) are triggered.
     *
     * @param  int|string  $id  The primary key value
     * @return bool True if deleted, false if not found
     */
    public function deleteById(int|string $id): bool
    {
        $model = $this->findById($id);

        if ($model === null) {
            return false;
        }

        $model->delete();

        return true;
    }

    /**
     * Delete a record by its primary key or throw exception.
     *
     * This method uses Eloquent's model-level delete, ensuring that
     * model events (deleting/deleted) are triggered.
     *
     * @param  int|string  $id  The primary key value
     *
     * @throws ModelNotFoundException
     */
    public function deleteByIdOrFail(int|string $id): bool
    {
        $model = $this->findByIdOrFail($id);

        $model->delete();

        return true;
    }

    /**
     * Count records matching conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     */
    public function count(array $conditions = []): int
    {
        $query = $this->newQuery();

        if (! empty($conditions)) {
            $query->where($conditions);
        }

        return $query->count();
    }

    /**
     * Check if records exist.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     */
    public function exists(array $conditions): bool
    {
        return $this->newQuery()
            ->where($conditions)
            ->exists();
    }

    /**
     * Insert records without creating models.
     *
     * @param  array<int|string, mixed>  $values  Values to insert
     */
    public function insert(array $values): bool
    {
        return $this->newQuery()->insert($values);
    }

    /**
     * Insert a record and get the ID.
     *
     * @param  array<string, mixed>  $values  Values to insert
     */
    public function insertGetId(array $values): int
    {
        return $this->newQuery()->insertGetId($values);
    }

    /**
     * Find or create a record.
     *
     * @param  array<string, mixed>  $conditions  Attributes to match
     * @param  array<string, mixed>  $values  Additional values for creation
     */
    public function firstOrCreate(array $conditions, array $values = []): Model
    {
        return $this->newQuery()->firstOrCreate($conditions, $values);
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
        return $this->newQuery()->upsert($values, $uniqueBy, $update);
    }

    /**
     * Process records in chunks.
     *
     * @param  int  $count  Chunk size
     * @param  callable  $callback  Callback for each chunk
     */
    public function chunk(int $count, callable $callback): bool
    {
        return $this->newQuery()->chunk($count, $callback);
    }

    /**
     * Execute operations within a database transaction.
     *
     * @throws Throwable
     */
    public function transaction(callable $callback): mixed
    {
        return $this->model->getConnection()->transaction($callback);
    }

    /**
     * Get the model's table name.
     */
    public function getTable(): string
    {
        return $this->model->getTable();
    }

    /**
     * Get the underlying model.
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Set a base builder for queries.
     *
     * When set, newQuery() will clone this builder instead of creating a fresh one.
     * Useful for applying global scopes or filters to all queries.
     */
    public function withBuilder(Builder $builder): static
    {
        $this->withBuilder = $builder;

        return $this;
    }

    /**
     * Get new query builder (alias for newQuery for interface compatibility).
     */
    public function getBuilder(): Builder
    {
        return $this->newQuery();
    }

    /**
     * Reset the builder state (no-op, kept for interface compatibility).
     *
     * @deprecated No longer needed since each method creates a fresh builder.
     */
    public function resetBuilder(): static
    {
        // No-op: Each method now creates its own fresh builder
        return $this;
    }
}
