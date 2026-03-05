<?php

declare(strict_types=1);

namespace Frontier\Repositories;

use Frontier\Repositories\Contracts\Repository as RepositoryContract;
use Frontier\Repositories\Traits\Retrievable;
use Frontier\Repositories\ValueObjects\QueryOptions;
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
     * Wraps all inserts in a single transaction for atomicity — either all records
     * are created or none are. Each record fires Eloquent model events (creating/created),
     * applies casts and mutators.
     *
     * For high-volume inserts where lifecycle events are not required, prefer insertMany().
     *
     * @param  array<int, array<string, mixed>>  $records  Array of records to create
     * @return Collection<int, Model> Collection of created models
     */
    public function createMany(array $records): Collection
    {
        return $this->transaction(function () use ($records): Collection {
            $models = new Collection;
            $query = $this->newQuery();

            foreach ($records as $record) {
                $models->push($query->create($record));
            }

            return $models;
        });
    }

    /**
     * Insert multiple records using chunked bulk INSERT statements.
     *
     * Bypasses Eloquent model instantiation entirely — no model events (creating/created),
     * casts, mutators, or timestamps are applied. Use this for high-volume data imports
     * where performance matters more than lifecycle hooks.
     *
     * Prefer createMany() when model events and casts are required.
     *
     * @param  array<int, array<string, mixed>>  $records    Records to insert
     * @param  int  $chunkSize  Rows per INSERT statement (default 500)
     */
    public function insertMany(array $records, int $chunkSize = 500): bool
    {
        foreach (array_chunk($records, $chunkSize) as $chunk) {
            if (! $this->newQuery()->insert($chunk)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all records.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>|QueryOptions  $options  Query options
     */
    public function get(array $columns = ['*'], array|QueryOptions $options = []): Collection
    {
        return $this->getRetrieveQuery($columns, $this->resolveOptions($options))->get();
    }

    /**
     * Get records by conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>|QueryOptions  $options  Query options
     */
    public function getBy(array $conditions, array $columns = ['*'], array|QueryOptions $options = []): Collection
    {
        return $this->getRetrieveQuery($columns, $this->resolveOptions($options))
            ->where($conditions)
            ->get();
    }

    /**
     * Get records matching any of the provided condition groups (OR logic).
     *
     * Each condition group is AND-chained internally; groups are OR-chained together:
     * getByOr([['a' => 1], ['b' => 2]]) → WHERE (a = 1) OR (b = 2)
     *
     * @param  array<int, array<string, mixed>>  $conditionGroups
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>|QueryOptions  $options  Query options
     * @return Collection<int, Model>
     */
    public function getByOr(array $conditionGroups, array $columns = ['*'], array|QueryOptions $options = []): Collection
    {
        $query = $this->getRetrieveQuery($columns, $this->resolveOptions($options));

        foreach ($conditionGroups as $index => $conditions) {
            $index === 0 ? $query->where($conditions) : $query->orWhere($conditions);
        }

        return $query->get();
    }

    /**
     * Paginate with total count (for UI with page numbers).
     * Uses 2 queries: COUNT(*) + data fetch.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>|QueryOptions  $options  Query options
     */
    public function paginate(
        array $columns = ['*'],
        array|QueryOptions $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): LengthAwarePaginator {
        $perPage ??= $this->model->getPerPage();

        return $this->getRetrieveQueryForPagination($columns, $this->resolveOptions($options))
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Paginate records by conditions with total count.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>|QueryOptions  $options  Query options
     */
    public function paginateBy(
        array $conditions,
        array $columns = ['*'],
        array|QueryOptions $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): LengthAwarePaginator {
        $perPage ??= $this->model->getPerPage();

        return $this->getRetrieveQueryForPagination($columns, $this->resolveOptions($options))
            ->where($conditions)
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Simple paginate without total count (for "Next/Prev" UI).
     * Uses 1 query only — faster than paginate().
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>|QueryOptions  $options  Query options
     */
    public function simplePaginate(
        array $columns = ['*'],
        array|QueryOptions $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): Paginator {
        $perPage ??= $this->model->getPerPage();

        return $this->getRetrieveQueryForPagination($columns, $this->resolveOptions($options))
            ->simplePaginate(perPage: $perPage, page: $page);
    }

    /**
     * Cursor-based pagination for large datasets (100k+ rows).
     * O(1) performance — no offset scanning, constant speed for any "page".
     * Best for: infinite scroll, API endpoints, mobile apps.
     *
     * NOTE: Requires consistent ORDER BY column (usually 'id' or 'created_at').
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>|QueryOptions  $options  Query options
     */
    public function cursorPaginate(
        array $columns = ['*'],
        array|QueryOptions $options = [],
        ?int $perPage = null,
        ?string $cursor = null
    ): CursorPaginator {
        $perPage ??= $this->model->getPerPage();

        return $this->getRetrieveQueryForPagination($columns, $this->resolveOptions($options))
            ->cursorPaginate(perPage: $perPage, cursor: $cursor);
    }

    /**
     * Resolve $options to a plain array.
     *
     * Accepts either a legacy array or a QueryOptions DTO, converting the latter
     * to its array representation for internal query building.
     *
     * @param  array<string, mixed>|QueryOptions  $options
     * @return array<string, mixed>
     */
    protected function resolveOptions(array|QueryOptions $options): array
    {
        return $options instanceof QueryOptions ? $options->toArray() : $options;
    }

    /**
     * Find a record by its primary key.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<int, string>  $columns  Columns to select
     */
    public function find(int|string $id, array $columns = ['*']): ?Model
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
    public function findOrFail(int|string $id, array $columns = ['*']): Model
    {
        return $this->newQuery()
            ->select($this->prefixColumns($columns))
            ->findOrFail($id);
    }

    /**
     * Find multiple records by their primary keys.
     *
     * Returns only found records — missing IDs are silently omitted.
     *
     * @param  array<int, int|string>  $ids
     * @param  array<int, string>  $columns
     * @return Collection<int, Model>
     */
    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        return $this->newQuery()
            ->select($this->prefixColumns($columns))
            ->whereIn($this->model->getKeyName(), $ids)
            ->get();
    }

    /**
     * Find multiple records by their primary keys or throw if any are missing.
     *
     * @param  array<int, int|string>  $ids
     * @param  array<int, string>  $columns
     * @return Collection<int, Model>
     *
     * @throws ModelNotFoundException
     */
    public function findManyOrFail(array $ids, array $columns = ['*']): Collection
    {
        $models = $this->findMany($ids, $columns);

        if ($models->count() !== count(array_unique($ids))) {
            throw (new ModelNotFoundException)->setModel($this->model::class, $ids);
        }

        return $models;
    }

    /**
     * Find a single record by conditions.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     */
    public function findBy(array $conditions, array $columns = ['*']): ?Model
    {
        return $this->newQuery()
            ->select($this->prefixColumns($columns))
            ->where($conditions)
            ->first();
    }

    /**
     * Find a record by conditions or throw exception.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<int, string>  $columns  Columns to select
     *
     * @throws ModelNotFoundException
     */
    public function findByOrFail(array $conditions, array $columns = ['*']): Model
    {
        return $this->newQuery()
            ->select($this->prefixColumns($columns))
            ->where($conditions)
            ->firstOrFail();
    }

    /**
     * Find a single record matching any of the provided condition groups (OR logic).
     *
     * Each condition group is AND-chained internally; groups are OR-chained together:
     * findByOr([['a' => 1], ['b' => 2]]) → WHERE (a = 1) OR (b = 2)
     *
     * @param  array<int, array<string, mixed>>  $conditionGroups
     * @param  array<int, string>  $columns
     */
    public function findByOr(array $conditionGroups, array $columns = ['*']): ?Model
    {
        $query = $this->newQuery()->select($this->prefixColumns($columns));

        foreach ($conditionGroups as $index => $conditions) {
            $index === 0 ? $query->where($conditions) : $query->orWhere($conditions);
        }

        return $query->first();
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
            throw (new ModelNotFoundException)->setModel($this->model::class);
        }

        return $affected;
    }

    /**
     * Update records matching conditions using Eloquent models.
     *
     * Retrieves all matching records and updates each individually using
     * Eloquent's model-level update, firing events and applying casts per record.
     *
     * Performance: N+1 queries (1 SELECT + 1 UPDATE per record).
     * Use update() for bulk operations where lifecycle isn't needed.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<string, mixed>  $values  Values to update
     * @return Collection<int, Model> Collection of updated models
     */
    public function updateEach(array $conditions, array $values): Collection
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
    public function updateEachOrFail(array $conditions, array $values): Collection
    {
        $models = $this->updateEach($conditions, $values);

        if ($models->isEmpty()) {
            throw (new ModelNotFoundException)->setModel($this->model::class);
        }

        return $models;
    }

    /**
     * Update a record by its primary key.
     *
     * Uses Eloquent's model-level update, triggering casts, mutators, and events.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<string, mixed>  $values  Values to update
     * @return Model|null The updated model or null if not found
     */
    public function updateById(int|string $id, array $values): ?Model
    {
        $model = $this->find($id);

        if ($model === null) {
            return null;
        }

        $model->update($values);

        return $model;
    }

    /**
     * Update a record by its primary key or throw exception.
     *
     * Uses Eloquent's model-level update, triggering casts, mutators, and events.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<string, mixed>  $values  Values to update
     *
     * @throws ModelNotFoundException
     */
    public function updateByIdOrFail(int|string $id, array $values): Model
    {
        $model = $this->findOrFail($id);

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
            throw (new ModelNotFoundException)->setModel($this->model::class);
        }

        return $deleted;
    }

    /**
     * Delete records matching conditions using Eloquent models.
     *
     * Retrieves all matching records and deletes each individually using
     * Eloquent's model-level delete, firing events and respecting soft deletes.
     *
     * Performance: N+1 queries (1 SELECT + 1 DELETE per record).
     * Use delete() for bulk operations where lifecycle isn't needed.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return Collection<int, Model> Collection of deleted models
     */
    public function deleteEach(array $conditions): Collection
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
    public function deleteEachOrFail(array $conditions): Collection
    {
        $models = $this->deleteEach($conditions);

        if ($models->isEmpty()) {
            throw (new ModelNotFoundException)->setModel($this->model::class);
        }

        return $models;
    }

    /**
     * Delete a record by its primary key.
     *
     * Uses Eloquent's model-level delete, triggering events and respecting soft deletes.
     *
     * @param  int|string  $id  The primary key value
     * @return bool True if deleted, false if not found
     */
    public function deleteById(int|string $id): bool
    {
        $model = $this->find($id);

        if ($model === null) {
            return false;
        }

        $model->delete();

        return true;
    }

    /**
     * Delete a record by its primary key or throw exception.
     *
     * Uses Eloquent's model-level delete, triggering events and respecting soft deletes.
     *
     * @param  int|string  $id  The primary key value
     *
     * @throws ModelNotFoundException
     */
    public function deleteByIdOrFail(int|string $id): bool
    {
        $model = $this->findOrFail($id);

        $model->delete();

        return true;
    }

    /**
     * Delete multiple records by their primary keys using Eloquent models.
     *
     * Runs inside a transaction and uses a cursor for memory efficiency.
     * Model events (deleting/deleted) are triggered for each record.
     *
     * @param  array<int, int|string>  $ids  The primary key values to delete
     * @return int Number of deleted rows
     *
     * @throws Throwable
     */
    public function deleteMany(array $ids): int
    {
        return $this->transaction(function () use ($ids): int {
            $deleted = 0;
            $this->newQuery()
                ->whereIn($this->model->getKeyName(), $ids)
                ->cursor()
                ->each(function (Model $model) use (&$deleted): void {
                    $model->delete();
                    $deleted++;
                });

            return $deleted;
        });
    }

    /**
     * Delete multiple records by their primary keys or throw if none found.
     *
     * @param  array<int, int|string>  $ids  The primary key values to delete
     * @return int Number of deleted rows
     *
     * @throws ModelNotFoundException
     * @throws Throwable
     */
    public function deleteManyOrFail(array $ids): int
    {
        $deleted = $this->deleteMany($ids);

        if ($deleted === 0) {
            throw (new ModelNotFoundException)->setModel($this->model::class);
        }

        return $deleted;
    }

    /**
     * Restore soft-deleted records matching conditions.
     *
     * Requires the model to use the SoftDeletes trait. Searches only within
     * trashed records and restores matching ones.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return int Number of restored rows
     */
    public function restore(array $conditions): int
    {
        return $this->newQuery()
            ->withTrashed()
            ->where($conditions)
            ->restore();
    }

    /**
     * Restore a single soft-deleted record by its primary key.
     *
     * Requires the model to use the SoftDeletes trait.
     *
     * @param  int|string  $id  The primary key value
     * @return bool True if restored, false if not found in trash
     */
    public function restoreById(int|string $id): bool
    {
        $model = $this->newQuery()
            ->withTrashed()
            ->find($id);

        if ($model === null) {
            return false;
        }

        return (bool) $model->restore();
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
     * Reset the base builder state.
     *
     * Clears any builder set via withBuilder(), restoring newQuery() to create
     * a fresh builder from the model.
     */
    public function resetBuilder(): static
    {
        $this->withBuilder = null;

        return $this;
    }
}
