<?php

namespace Frontier\Repositories\Contracts;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Throwable;

interface EloquentRepository extends Repository
{
    /**
     * Create a new model record with the given values
     *
     * @param  array  $values  The attributes to create the model with
     * @return Model The newly created model instance
     */
    public function create(array $values): Model;

    /**
     * Update models matching the given conditions
     *
     * @param  array  $conditions  The where conditions to match
     * @param  array  $values  The attributes to update
     * @return int The number of affected rows
     */
    public function update(array $conditions, array $values): int;

    /**
     * Delete models matching the given conditions
     *
     * @param  array  $conditions  The where conditions to match
     * @return int The number of deleted rows
     */
    public function delete(array $conditions): int;

    /**
     * Insert multiple records in a single query
     *
     * @param  array  $values  Array of attribute sets to insert
     * @return bool True on success, false on failure
     */
    public function insert(array $values): bool;

    /**
     * Insert a new record and get the primary key
     *
     * @param  array  $values  The attributes to insert
     * @return int The ID of the newly created record
     */
    public function insertGetId(array $values): int;

    /**
     * Insert new records or update existing ones (mass "upsert")
     *
     * @param  array  $values  The records to insert or update
     * @param  array  $uniqueBy  Columns that uniquely identify records
     * @param  array|null  $update  Columns to update if record exists
     * @return int Number of affected records
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): int;

    /**
     * Retrieve all records matching the given criteria
     *
     * @param  array  $columns  The columns to select (default: all)
     * @param  array  $options  Additional query options:
     *                          - filters: array of where conditions
     *                          - sort: field to sort by
     *                          - direction: sort direction (asc/desc)
     *                          - with: eager load relationships
     * @return Collection The collection of matching models
     */
    public function retrieve(array $columns = ['*'], array $options = []): Collection;

    /**
     * Retrieve paginated results matching the given criteria
     *
     * @param  array  $columns  The columns to select
     * @param  array  $options  Additional query options (see retrieve())
     * @param  string  $pageName  Pagination query string parameter name
     * @param  int|null  $page  Specific page number to load
     * @return LengthAwarePaginator The paginated results
     */
    public function retrievePaginate(
        array $columns = ['*'],
        array $options = [],
        string $pageName = 'page',
        ?int $page = null
    ): LengthAwarePaginator;

    /**
     * Find the first model matching the given conditions
     *
     * @param  array  $conditions  The where conditions to match
     * @param  array  $columns  The columns to select
     * @return Model|null The matching model or null if not found
     */
    public function find(array $conditions, array $columns = ['*']): ?Model;

    /**
     * Find the first model matching the given conditions or throw exception
     *
     * @param  array  $conditions  The where conditions to match
     * @param  array  $columns  The columns to select
     * @return Model The matching model
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(array $conditions, array $columns = ['*']): Model;

    /**
     * Update existing model or create new one if it doesn't exist
     *
     * @param  array  $conditions  The attributes to match existing model
     * @param  array  $values  The attributes to update/create
     * @return Model The updated or created model
     */
    public function updateOrCreate(array $conditions, array $values): Model;

    /**
     * Get first matching model or create new one if none exists
     *
     * @param  array  $conditions  The attributes to match
     * @param  array  $values  Additional attributes for creation
     * @return Model The matched or created model
     */
    public function firstOrCreate(array $conditions, array $values = []): Model;

    /**
     * Count models matching the given conditions
     *
     * @param  array  $conditions  The where conditions to match
     * @return int The count of matching records
     */
    public function count(array $conditions = []): int;

    /**
     * Check if any models match the given conditions
     *
     * @param  array  $conditions  The where conditions to match
     * @return bool True if at least one match exists
     */
    public function exists(array $conditions): bool;

    /**
     * Process models in batches to reduce memory usage
     *
     * @param  int  $count  Number of models to process per batch
     * @param  callable  $callback  Function to process each batch
     * @return bool False if processing was interrupted
     */
    public function chunk(int $count, callable $callback): bool;

    /**
     * Execute callback within a database transaction
     *
     * @param  callable  $callback  The transaction logic
     * @return mixed The callback's return value
     *
     * @throws Throwable Any exceptions from the callback
     */
    public function transaction(callable $callback): mixed;

    /**
     * Set the active query builder instance
     *
     * @param  Builder  $builder  Builder instance to use
     * @return $this
     */
    public function withBuilder(Builder $builder): self;

    /**
     * Reset the query builder to fresh instance
     *
     * @return $this
     */
    public function resetBuilder(): self;

    /**
     * Get the model's database table name
     *
     * @return string The table name
     */
    public function getTable(): string;

    /**
     * Get the underlying model instance
     *
     * @return Model The repository's model
     */
    public function getModel(): Model;

    /**
     * Get the current query builder instance
     *
     * @return Builder The active builder
     */
    public function getBuilder(): Builder;
}
