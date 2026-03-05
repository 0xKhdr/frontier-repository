<?php

declare(strict_types=1);

namespace Frontier\Repositories;

use Closure;
use Frontier\Repositories\Contracts\Repository as RepositoryContract;
use Frontier\Repositories\Contracts\RepositoryCache as RepositoryCacheContract;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ReflectionException;
use ReflectionFunction;

/**
 * Cacheable repository decorator.
 *
 * Wraps any repository and adds caching for reads, invalidation on writes.
 *
 * Usage in ServiceProvider:
 *   $this->app->bind(UserRepositoryInterface::class, fn($app) =>
 *       new RepositoryCache($app->make(UserRepository::class))
 *   );
 */
class BaseRepositoryCache implements RepositoryCacheContract, RepositoryContract
{
    protected bool $skipCache = false;

    protected bool $forceRefresh = false;

    /**
     * Per-call TTL override — consumed after one cached() call.
     */
    protected ?int $onceTtl = null;

    /**
     * Create a new cacheable repository decorator.
     *
     * @param  RepositoryContract  $repository  The repository to wrap
     * @param  int  $ttl  Cache TTL in seconds
     * @param  string|null  $driver  Cache driver name
     * @param  string|null  $prefix  Cache key prefix
     */
    public function __construct(
        protected RepositoryContract $repository,
        protected int $ttl = 3600,
        protected ?string $driver = null,
        protected ?string $prefix = null
    ) {}

    /**
     * Get the cache TTL in seconds.
     */
    public function getCacheTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Get the cache key prefix.
     */
    public function getCachePrefix(): string
    {
        return $this->prefix ?? $this->repository->getTable();
    }

    /**
     * Get the cache driver name.
     */
    public function getCacheDriver(): ?string
    {
        return $this->driver ?? config('repository-cache.driver');
    }

    /**
     * Determine if caching should be used.
     */
    public function shouldCache(): bool
    {
        return ! $this->skipCache && config('repository-cache.enabled', true);
    }

    /**
     * Clear all cache for this repository.
     *
     * Returns true when the tagged cache was successfully flushed (Redis, Memcached).
     * Returns false for drivers without tag support (file, array, database) — cache
     * entries cannot be invalidated and a warning is logged. Use a tag-aware driver
     * in production, or disable caching entirely via REPOSITORY_CACHE_ENABLED=false.
     */
    public function clearCache(): bool
    {
        $store = Cache::store($this->getCacheDriver());

        if (! $store->supportsTags()) {
            Log::warning('Repository cache could not be cleared: the configured cache driver does not support tags. Switch to a tag-aware driver (Redis, Memcached) or disable caching via REPOSITORY_CACHE_ENABLED=false.', [
                'driver' => $this->getCacheDriver() ?? 'default',
                'prefix' => $this->getCachePrefix(),
            ]);

            return false;
        }

        return $store->tags([$this->getCachePrefix()])->flush();
    }

    /**
     * Skip cache for the next query.
     */
    public function withoutCache(): static
    {
        $this->skipCache = true;

        return $this;
    }

    /**
     * Force refresh the cache on the next query.
     */
    public function refreshCache(): static
    {
        $this->forceRefresh = true;

        return $this;
    }

    /**
     * Override the cache TTL for the next read operation only.
     *
     * Resets automatically after the next cached() call.
     *
     *   $repo->cacheFor(30)->find($id);   // cached for 30 seconds
     *   $repo->find($id);                  // back to default TTL
     */
    public function cacheFor(int $seconds): static
    {
        $this->onceTtl = $seconds;

        return $this;
    }

    /**
     * Get all records (cached).
     *
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $options
     */
    public function get(array $columns = ['*'], array $options = []): Collection
    {
        return $this->cached('get', ['columns' => $columns, 'options' => $options], fn () => $this->repository->get($columns, $options));
    }

    /**
     * Get records by conditions (cached).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $options
     */
    public function getBy(array $conditions, array $columns = ['*'], array $options = []): Collection
    {
        return $this->cached('getBy', ['conditions' => $conditions, 'columns' => $columns, 'options' => $options], fn () => $this->repository->getBy($conditions, $columns, $options));
    }

    /**
     * Get records matching any condition group — OR logic (cached).
     *
     * @param  array<int, array<string, mixed>>  $conditionGroups
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $options
     * @return Collection<int, Model>
     */
    public function getByOr(array $conditionGroups, array $columns = ['*'], array $options = []): Collection
    {
        return $this->cached('getByOr', ['conditionGroups' => $conditionGroups, 'columns' => $columns, 'options' => $options], fn () => $this->repository->getByOr($conditionGroups, $columns, $options));
    }

    /**
     * Paginate with total count (cached).
     *
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $options
     */
    public function paginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): LengthAwarePaginator {
        return $this->cached('paginate', ['columns' => $columns, 'options' => $options, 'perPage' => $perPage, 'page' => $page], fn () => $this->repository->paginate($columns, $options, $perPage, $page));
    }

    /**
     * Paginate records by conditions with total count (cached).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $options
     */
    public function paginateBy(
        array $conditions,
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): LengthAwarePaginator {
        return $this->cached('paginateBy', ['conditions' => $conditions, 'columns' => $columns, 'options' => $options, 'perPage' => $perPage, 'page' => $page], fn () => $this->repository->paginateBy($conditions, $columns, $options, $perPage, $page));
    }

    /**
     * Simple pagination without total count (cached).
     *
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $options
     */
    public function simplePaginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): Paginator {
        return $this->cached('simplePaginate', ['columns' => $columns, 'options' => $options, 'perPage' => $perPage, 'page' => $page], fn () => $this->repository->simplePaginate($columns, $options, $perPage, $page));
    }

    /**
     * Cursor-based pagination for large datasets (cached).
     *
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $options
     */
    public function cursorPaginate(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?string $cursor = null
    ): CursorPaginator {
        return $this->cached('cursorPaginate', ['columns' => $columns, 'options' => $options, 'perPage' => $perPage, 'cursor' => $cursor], fn () => $this->repository->cursorPaginate($columns, $options, $perPage, $cursor));
    }

    /**
     * Find a record by its primary key (cached).
     *
     * @param  int|string  $id
     * @param  array<int, string>  $columns
     */
    public function find(int|string $id, array $columns = ['*']): ?Model
    {
        return $this->cached('find', ['id' => $id, 'columns' => $columns], fn () => $this->repository->find($id, $columns));
    }

    /**
     * Find a record by its primary key or throw exception (cached).
     *
     * @param  int|string  $id
     * @param  array<int, string>  $columns
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(int|string $id, array $columns = ['*']): Model
    {
        return $this->cached('findOrFail', ['id' => $id, 'columns' => $columns], fn () => $this->repository->findOrFail($id, $columns));
    }

    /**
     * Find multiple records by their primary keys (cached).
     *
     * @param  array<int, int|string>  $ids
     * @param  array<int, string>  $columns
     * @return Collection<int, Model>
     */
    public function findMany(array $ids, array $columns = ['*']): Collection
    {
        return $this->cached('findMany', ['ids' => $ids, 'columns' => $columns], fn () => $this->repository->findMany($ids, $columns));
    }

    /**
     * Find multiple records by primary keys or throw if any are missing (cached).
     *
     * @param  array<int, int|string>  $ids
     * @param  array<int, string>  $columns
     * @return Collection<int, Model>
     *
     * @throws ModelNotFoundException
     */
    public function findManyOrFail(array $ids, array $columns = ['*']): Collection
    {
        return $this->cached('findManyOrFail', ['ids' => $ids, 'columns' => $columns], fn () => $this->repository->findManyOrFail($ids, $columns));
    }

    /**
     * Find a single record by conditions (cached).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<int, string>  $columns
     */
    public function findBy(array $conditions, array $columns = ['*']): ?Model
    {
        return $this->cached('findBy', ['conditions' => $conditions, 'columns' => $columns], fn () => $this->repository->findBy($conditions, $columns));
    }

    /**
     * Find a record by conditions or throw exception (cached).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<int, string>  $columns
     *
     * @throws ModelNotFoundException
     */
    public function findByOrFail(array $conditions, array $columns = ['*']): Model
    {
        return $this->cached('findByOrFail', ['conditions' => $conditions, 'columns' => $columns], fn () => $this->repository->findByOrFail($conditions, $columns));
    }

    /**
     * Find a single record matching any condition group — OR logic (cached).
     *
     * @param  array<int, array<string, mixed>>  $conditionGroups
     * @param  array<int, string>  $columns
     */
    public function findByOr(array $conditionGroups, array $columns = ['*']): ?Model
    {
        return $this->cached('findByOr', ['conditionGroups' => $conditionGroups, 'columns' => $columns], fn () => $this->repository->findByOr($conditionGroups, $columns));
    }

    /**
     * Count records (cached).
     *
     * @param  array<string, mixed>  $conditions
     */
    public function count(array $conditions = []): int
    {
        return $this->cached('count', ['conditions' => $conditions], fn () => $this->repository->count($conditions));
    }

    /**
     * Check if records exist (cached).
     *
     * @param  array<string, mixed>  $conditions
     */
    public function exists(array $conditions): bool
    {
        return $this->cached('exists', ['conditions' => $conditions], fn () => $this->repository->exists($conditions));
    }

    /**
     * Create a new record (invalidates cache).
     *
     * @param  array<string, mixed>  $values
     */
    public function create(array $values): Model
    {
        return tap($this->repository->create($values), fn (): bool => $this->clearCache());
    }

    /**
     * Create multiple records (invalidates cache).
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    public function createMany(array $records): Collection
    {
        return tap($this->repository->createMany($records), fn (): bool => $this->clearCache());
    }

    /**
     * Update records (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $values
     */
    public function update(array $conditions, array $values): int
    {
        return tap($this->repository->update($conditions, $values), fn (): bool => $this->clearCache());
    }

    /**
     * Update records or throw if none found (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $values
     *
     * @throws ModelNotFoundException
     */
    public function updateOrFail(array $conditions, array $values): int
    {
        return tap($this->repository->updateOrFail($conditions, $values), fn (): bool => $this->clearCache());
    }

    /**
     * Update records using Eloquent models (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $values
     * @return Collection<int, Model>
     */
    public function updateEach(array $conditions, array $values): Collection
    {
        return tap($this->repository->updateEach($conditions, $values), fn (): bool => $this->clearCache());
    }

    /**
     * Update records using Eloquent models or throw if none found (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $values
     * @return Collection<int, Model>
     *
     * @throws ModelNotFoundException
     */
    public function updateEachOrFail(array $conditions, array $values): Collection
    {
        return tap($this->repository->updateEachOrFail($conditions, $values), fn (): bool => $this->clearCache());
    }

    /**
     * Update a record by its primary key (invalidates cache).
     *
     * @param  int|string  $id
     * @param  array<string, mixed>  $values
     * @return Model|null
     */
    public function updateById(int|string $id, array $values): ?Model
    {
        return tap($this->repository->updateById($id, $values), fn (): bool => $this->clearCache());
    }

    /**
     * Update a record by its primary key or throw exception (invalidates cache).
     *
     * @param  int|string  $id
     * @param  array<string, mixed>  $values
     *
     * @throws ModelNotFoundException
     */
    public function updateByIdOrFail(int|string $id, array $values): Model
    {
        return tap($this->repository->updateByIdOrFail($id, $values), fn (): bool => $this->clearCache());
    }

    /**
     * Delete records (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     */
    public function delete(array $conditions): int
    {
        return tap($this->repository->delete($conditions), fn (): bool => $this->clearCache());
    }

    /**
     * Delete records or throw if none found (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     *
     * @throws ModelNotFoundException
     */
    public function deleteOrFail(array $conditions): int
    {
        return tap($this->repository->deleteOrFail($conditions), fn (): bool => $this->clearCache());
    }

    /**
     * Delete records using Eloquent models (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     * @return Collection<int, Model>
     */
    public function deleteEach(array $conditions): Collection
    {
        return tap($this->repository->deleteEach($conditions), fn (): bool => $this->clearCache());
    }

    /**
     * Delete records using Eloquent models or throw if none found (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     * @return Collection<int, Model>
     *
     * @throws ModelNotFoundException
     */
    public function deleteEachOrFail(array $conditions): Collection
    {
        return tap($this->repository->deleteEachOrFail($conditions), fn (): bool => $this->clearCache());
    }

    /**
     * Delete a record by its primary key (invalidates cache).
     *
     * @param  int|string  $id
     * @return bool
     */
    public function deleteById(int|string $id): bool
    {
        return tap($this->repository->deleteById($id), fn (): bool => $this->clearCache());
    }

    /**
     * Delete a record by its primary key or throw exception (invalidates cache).
     *
     * @param  int|string  $id
     *
     * @throws ModelNotFoundException
     */
    public function deleteByIdOrFail(int|string $id): bool
    {
        return tap($this->repository->deleteByIdOrFail($id), fn (): bool => $this->clearCache());
    }

    /**
     * Delete multiple records by primary keys (invalidates cache).
     *
     * @param  array<int, int|string>  $ids
     */
    public function deleteMany(array $ids): int
    {
        return tap($this->repository->deleteMany($ids), fn (): bool => $this->clearCache());
    }

    /**
     * Delete multiple records by primary keys or throw if none found (invalidates cache).
     *
     * @param  array<int, int|string>  $ids
     *
     * @throws ModelNotFoundException
     */
    public function deleteManyOrFail(array $ids): int
    {
        return tap($this->repository->deleteManyOrFail($ids), fn (): bool => $this->clearCache());
    }

    /**
     * Update or create a record (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $values
     */
    public function updateOrCreate(array $conditions, array $values): Model
    {
        return tap($this->repository->updateOrCreate($conditions, $values), fn (): bool => $this->clearCache());
    }

    /**
     * Insert records (invalidates cache).
     *
     * @param  array<int|string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        return tap($this->repository->insert($values), fn (): bool => $this->clearCache());
    }

    /**
     * Insert a record and get the ID (invalidates cache).
     *
     * @param  array<string, mixed>  $values
     */
    public function insertGetId(array $values): int
    {
        return tap($this->repository->insertGetId($values), fn (): bool => $this->clearCache());
    }

    /**
     * Insert multiple records in chunks (invalidates cache).
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    public function insertMany(array $records, int $chunkSize = 500): bool
    {
        return tap($this->repository->insertMany($records, $chunkSize), fn (): bool => $this->clearCache());
    }

    /**
     * Restore soft-deleted records matching conditions (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     */
    public function restore(array $conditions): int
    {
        return tap($this->repository->restore($conditions), fn (): bool => $this->clearCache());
    }

    /**
     * Restore a single soft-deleted record by its primary key (invalidates cache).
     *
     * @param  int|string  $id
     */
    public function restoreById(int|string $id): bool
    {
        return tap($this->repository->restoreById($id), fn (): bool => $this->clearCache());
    }

    /**
     * Find or create a record (invalidates cache only when a new record is created).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $values
     */
    public function firstOrCreate(array $conditions, array $values = []): Model
    {
        $model = $this->repository->firstOrCreate($conditions, $values);

        if ($model->wasRecentlyCreated) {
            $this->clearCache();
        }

        return $model;
    }

    /**
     * Upsert records (invalidates cache).
     *
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): int
    {
        return tap($this->repository->upsert($values, $uniqueBy, $update), fn (): bool => $this->clearCache());
    }

    /**
     * Process records in chunks.
     */
    public function chunk(int $count, callable $callback): bool
    {
        return $this->repository->chunk($count, $callback);
    }

    /**
     * Execute operations within a transaction.
     */
    public function transaction(callable $callback): mixed
    {
        return $this->repository->transaction($callback);
    }

    /**
     * Get the underlying model.
     */
    public function getModel(): Model
    {
        return $this->repository->getModel();
    }

    /**
     * Get the model's table name.
     */
    public function getTable(): string
    {
        return $this->repository->getTable();
    }

    /**
     * Get the current query builder.
     */
    public function getBuilder(): Builder
    {
        return $this->repository->getBuilder();
    }

    /**
     * Reset the query builder.
     */
    public function resetBuilder(): static
    {
        $this->repository->resetBuilder();

        return $this;
    }

    /**
     * Set a base builder for queries.
     */
    public function withBuilder(Builder $builder): static
    {
        $this->repository->withBuilder($builder);

        return $this;
    }

    /**
     * Cache a query result.
     *
     * Flags ($skipCache, $forceRefresh, $onceTtl) are reset unconditionally via
     * try-finally — even if the callback or the cache driver throws an exception —
     * preventing flag leakage into subsequent calls.
     *
     * @param  array<string, mixed>  $params
     */
    protected function cached(string $method, array $params, callable $callback): mixed
    {
        try {
            if (! $this->shouldCache()) {
                return $callback();
            }

            $key = $this->key($method, $params);
            $store = $this->store();
            $ttl = $this->onceTtl ?? $this->ttl;

            if ($this->forceRefresh) {
                $store->forget($key);
            }

            return $store->remember($key, $ttl, $callback);
        } finally {
            $this->resetFlags();
        }
    }

    /**
     * Generate a closure-safe cache key.
     *
     * Replaces Closure instances with a stable fingerprint (file and line range)
     * before serialization, preventing "Serialization of 'Closure' is not allowed".
     *
     * @param  array<string, mixed>  $params
     *
     * @throws ReflectionException
     */
    protected function key(string $method, array $params = []): string
    {
        $this->replaceClosures($params);

        $this->ksortRecursive($params);

        return $this->getCachePrefix().':'.$method.':'.md5(serialize($params));
    }

    /**
     * Get the cache store instance, tagged when the driver supports it.
     */
    protected function store(): CacheContract
    {
        $store = Cache::store($this->getCacheDriver());

        return $store->supportsTags() ? $store->tags([$this->getCachePrefix()]) : $store;
    }

    /**
     * Reset all cache control flags.
     *
     * Called unconditionally in the cached() finally block to prevent
     * flag leakage when the callback or cache driver throws an exception.
     */
    protected function resetFlags(): void
    {
        $this->skipCache = false;
        $this->forceRefresh = false;
        $this->onceTtl = null;
    }

    /**
     * Recursively sort an array by keys.
     */
    protected function ksortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    /**
     * Recursively replace Closure instances with deterministic string fingerprints.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws ReflectionException
     */
    private function replaceClosures(array &$params): void
    {
        foreach ($params as &$value) {
            if ($value instanceof Closure) {
                $value = $this->closureFingerprint($value);
            } elseif (is_array($value)) {
                $this->replaceClosures($value);
            }
        }
    }

    /**
     * Generate a deterministic fingerprint for a Closure.
     *
     * @throws ReflectionException
     */
    private function closureFingerprint(Closure $closure): string
    {
        $ref = new ReflectionFunction($closure);

        return '__closure@'.$ref->getFileName().':'.$ref->getStartLine().'-'.$ref->getEndLine();
    }
}
