<?php

declare(strict_types=1);

namespace Frontier\Repositories;

use Frontier\Repositories\Contracts\CacheableRepository as CacheableRepositoryContract;
use Frontier\Repositories\Contracts\Repository as RepositoryContract;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cacheable repository decorator.
 *
 * Wraps any repository and adds caching for reads, invalidation on writes.
 *
 * Usage in ServiceProvider:
 *   $this->app->bind(UserRepositoryInterface::class, fn($app) =>
 *       new BaseCacheableRepository($app->make(UserRepository::class))
 *   );
 */
class BaseCacheableRepository implements CacheableRepositoryContract, RepositoryContract
{
    protected bool $skipCache = false;

    protected bool $forceRefresh = false;

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
     */
    public function clearCache(): bool
    {
        if ($this->supportsTags()) {
            return Cache::store($this->getCacheDriver())->tags([$this->getCachePrefix()])->flush();
        }

        return true;
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
     * Retrieve all records (cached).
     *
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $options
     */
    public function retrieve(array $columns = ['*'], array $options = []): Collection
    {
        return $this->cached('retrieve', compact('columns', 'options'), fn () => $this->repository->retrieve($columns, $options));
    }

    /**
     * Retrieve paginated records (cached).
     *
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $options
     */
    public function retrievePaginate(array $columns = ['*'], array $options = [], string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        return $this->cached('retrievePaginate', compact('columns', 'options', 'pageName', 'page'), fn () => $this->repository->retrievePaginate($columns, $options, $pageName, $page));
    }

    /**
     * Find a single record (cached).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<int, string>  $columns
     */
    public function find(array $conditions, array $columns = ['*']): ?Model
    {
        return $this->cached('find', compact('conditions', 'columns'), fn () => $this->repository->find($conditions, $columns));
    }

    /**
     * Find a record or throw exception (cached).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<int, string>  $columns
     */
    public function findOrFail(array $conditions, array $columns = ['*']): Model
    {
        return $this->cached('findOrFail', compact('conditions', 'columns'), fn () => $this->repository->findOrFail($conditions, $columns));
    }

    /**
     * Count records (cached).
     *
     * @param  array<string, mixed>  $conditions
     */
    public function count(array $conditions = []): int
    {
        return $this->cached('count', compact('conditions'), fn () => $this->repository->count($conditions));
    }

    /**
     * Check if records exist (cached).
     *
     * @param  array<string, mixed>  $conditions
     */
    public function exists(array $conditions): bool
    {
        return $this->cached('exists', compact('conditions'), fn () => $this->repository->exists($conditions));
    }

    /**
     * Create a new record (invalidates cache).
     *
     * @param  array<string, mixed>  $values
     */
    public function create(array $values): Model
    {
        return tap($this->repository->create($values), fn () => $this->clearCache());
    }

    /**
     * Update records (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $values
     */
    public function update(array $conditions, array $values): int
    {
        return tap($this->repository->update($conditions, $values), fn () => $this->clearCache());
    }

    /**
     * Delete records (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     */
    public function delete(array $conditions): int
    {
        return tap($this->repository->delete($conditions), fn () => $this->clearCache());
    }

    /**
     * Update or create a record (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $values
     */
    public function updateOrCreate(array $conditions, array $values): Model
    {
        return tap($this->repository->updateOrCreate($conditions, $values), fn () => $this->clearCache());
    }

    /**
     * Insert records (invalidates cache).
     *
     * @param  array<int|string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        return tap($this->repository->insert($values), fn () => $this->clearCache());
    }

    /**
     * Insert a record and get the ID (invalidates cache).
     *
     * @param  array<string, mixed>  $values
     */
    public function insertGetId(array $values): int
    {
        return tap($this->repository->insertGetId($values), fn () => $this->clearCache());
    }

    /**
     * Find or create a record (invalidates cache).
     *
     * @param  array<string, mixed>  $conditions
     * @param  array<string, mixed>  $values
     */
    public function firstOrCreate(array $conditions, array $values = []): Model
    {
        return tap($this->repository->firstOrCreate($conditions, $values), fn () => $this->clearCache());
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
        return tap($this->repository->upsert($values, $uniqueBy, $update), fn () => $this->clearCache());
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
     * @param  array<string, mixed>  $params
     */
    protected function cached(string $method, array $params, callable $callback): mixed
    {
        if (! $this->shouldCache()) {
            $this->resetFlags();

            return $callback();
        }

        $key = $this->key($method, $params);
        $store = $this->store();

        if ($this->forceRefresh) {
            $store->forget($key);
        }

        $this->resetFlags();

        return $store->remember($key, $this->ttl, $callback);
    }

    /**
     * Generate a unique cache key.
     *
     * @param  array<string, mixed>  $params
     */
    protected function key(string $method, array $params = []): string
    {
        return $this->getCachePrefix().':'.$method.':'.md5(serialize($params));
    }

    /**
     * Get the cache store instance.
     */
    protected function store(): CacheContract
    {
        $store = Cache::store($this->getCacheDriver());

        return $this->supportsTags() ? $store->tags([$this->getCachePrefix()]) : $store;
    }

    /**
     * Check if the cache driver supports tags.
     */
    protected function supportsTags(): bool
    {
        try {
            return Cache::store($this->getCacheDriver())->supportsTags();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Reset cache control flags.
     */
    protected function resetFlags(): void
    {
        $this->skipCache = false;
        $this->forceRefresh = false;
    }
}
