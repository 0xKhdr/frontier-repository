# Frontier Repository — Codebase Analysis & Improvements

**Date:** 2026-03-04
**Scope:** Performance, readability, maintainability, architecture extensibility, and production-readiness
**Methodology:** Full read of every source file, test, stub, config, and interface

---

## Table of Contents

1. [Critical Bugs & Inconsistencies](#1-critical-bugs--inconsistencies)
2. [Config Keys Defined But Never Implemented](#2-config-keys-defined-but-never-implemented)
3. [Contract / Type Inconsistencies](#3-contract--type-inconsistencies)
4. [Performance Improvements](#4-performance-improvements)
5. [Missing Methods & Features](#5-missing-methods--features)
6. [Architecture Improvements — New Concepts](#6-architecture-improvements--new-concepts)
7. [Test Coverage Gaps](#7-test-coverage-gaps)
8. [Code Style & Maintainability](#8-code-style--maintainability)
9. [Priority Matrix](#9-priority-matrix)

---

## 1. Critical Bugs & Inconsistencies

### 1.1 `resetBuilder()` is a No-Op But `withBuilder()` Sets Permanent State

**File:** `src/BaseRepository.php:816`, `src/Contracts/Concerns/RepositoryUtility.php:101`

**Problem:** `withBuilder()` mutates `$this->withBuilder` permanently for the object's lifetime. `resetBuilder()` is marked `@deprecated` with a no-op body — it does nothing. Once a caller sets a base builder, there is **no way to clear it** for subsequent calls on the same repository instance. Callers that set `withBuilder()` inside a shared service and expect later calls to use a fresh builder will silently receive stale query state.

```php
// BaseRepository.php — current broken state
public function resetBuilder(): static
{
    // No-op: Each method now creates its own fresh builder  ← BUG
    return $this;
}
```

**Fix:** `resetBuilder()` must actually clear `$this->withBuilder`:

```php
public function resetBuilder(): static
{
    $this->withBuilder = null;

    return $this;
}
```

Remove the `@deprecated` tag — the method has real purpose. Alternatively, if it is truly never needed, **remove it from the `RepositoryUtility` contract** to avoid misleading callers.

---

### 1.2 `firstOrCreate` and `updateOrCreate` Always Clear Cache

**File:** `src/BaseRepositoryCache.php:603, 543`

**Problem:** Both methods call `clearCache()` unconditionally via `tap()`. When the record is **found** (not created), no data changed, so clearing the entire tag group is wasteful and causes unnecessary cache misses on reads immediately after.

Eloquent models expose `$model->wasRecentlyCreated` (bool), and `$model->wasChanged()` tells whether attributes actually changed after an update.

```php
// Current — always clears cache
public function firstOrCreate(array $conditions, array $values = []): Model
{
    return tap($this->repository->firstOrCreate($conditions, $values), fn (): bool => $this->clearCache());
}
```

**Fix:**

```php
public function firstOrCreate(array $conditions, array $values = []): Model
{
    $model = $this->repository->firstOrCreate($conditions, $values);

    if ($model->wasRecentlyCreated) {
        $this->clearCache();
    }

    return $model;
}

public function updateOrCreate(array $conditions, array $values): Model
{
    $model = $this->repository->updateOrCreate($conditions, $values);

    if ($model->wasRecentlyCreated || $model->wasChanged()) {
        $this->clearCache();
    }

    return $model;
}
```

---

### 1.3 `withBuilder()` on the Cache Decorator Does Not Invalidate Cache

**File:** `src/BaseRepositoryCache.php:672`

**Problem:** When `withBuilder()` is called on the cache decorator it updates the inner repository's base query builder, but does **not** clear the cache. The very next read will return stale cache results built without the new builder constraints.

```php
public function withBuilder(Builder $builder): static
{
    $this->repository->withBuilder($builder);  // ← cache not cleared

    return $this;
}
```

**Fix:**

```php
public function withBuilder(Builder $builder): static
{
    $this->repository->withBuilder($builder);
    $this->clearCache();

    return $this;
}
```

---

### 1.4 All `src/Actions/*.php` Files Are Missing `declare(strict_types=1)`

**Files:** `src/Actions/CountAction.php`, `CreateAction.php`, `DeleteAction.php`, `ExistsAction.php`, `FindAction.php`, `FindOrFailAction.php`, `RetrieveAction.php`, `RetrievePaginateAction.php`, `UpdateAction.php`, `UpdateOrCreateAction.php`

**Problem:** Every file in `src/Actions/` opens with `<?php` without the mandatory `declare(strict_types=1);` that every other file in the package uses. This violates the project's own coding standard stated in `CLAUDE.md` ("Every PHP file must start with `declare(strict_types=1);`").

```php
// Current — wrong
<?php

namespace Frontier\Repositories\Actions;

// Fix — correct
<?php

declare(strict_types=1);

namespace Frontier\Repositories\Actions;
```

---

## 2. Config Keys Defined But Never Implemented

`config/repository-cache.php` defines four configuration keys. Three of them are **completely ignored** by `BaseRepositoryCache` at runtime:

### 2.1 `cache_empty_results` — Never Checked

**File:** `config/repository-cache.php:75`, `src/BaseRepositoryCache.php`

**Problem:** The config documents: *"When true, null returns and empty collections are cached like any other result."* But `cached()` never reads this key. Empty results are always cached regardless of the setting.

**Implementation — add to `cached()`:**

```php
protected function cached(string $method, array $params, callable $callback): mixed
{
    try {
        if (! $this->shouldCache()) {
            return $callback();
        }

        $key   = $this->key($method, $params);
        $store = $this->store();
        $ttl   = $this->onceTtl ?? $this->ttl;

        if ($this->forceRefresh) {
            $store->forget($key);
        }

        if (! $store->has($key)) {
            $result     = $callback();
            $cacheEmpty = config('repository-cache.cache_empty_results', true);
            $isEmpty    = $result === null
                || ($result instanceof \Illuminate\Support\Collection && $result->isEmpty());

            if (! $isEmpty || $cacheEmpty) {
                $store->put($key, $result, $ttl);
            }

            return $result;
        }

        return $store->get($key);
    } finally {
        $this->resetFlags();
    }
}
```

---

### 2.2 `excluded_methods` — Never Checked

**File:** `config/repository-cache.php:89`

**Problem:** The config allows listing method names that should never be cached. `cached()` never reads this list, so the option has no effect.

**Implementation — add early-exit to `cached()`:**

```php
protected function cached(string $method, array $params, callable $callback): mixed
{
    try {
        $excluded = config('repository-cache.excluded_methods', []);

        if (! $this->shouldCache() || in_array($method, $excluded, true)) {
            return $callback();
        }
        // ... rest of method
    } finally {
        $this->resetFlags();
    }
}
```

---

### 2.3 `model_ttl` — Per-Model TTL Override Never Applied

**File:** `config/repository-cache.php:108`

**Problem:** The config documents per-table TTL overrides keyed by table name with the documented priority chain:
> `per-call cacheFor()` > `per-repository constructor TTL` > `per-model table TTL` > `global TTL`

But `cached()` only applies `$this->onceTtl ?? $this->ttl` — the `model_ttl` layer is completely absent.

**Implementation — add `resolveEffectiveTtl()`:**

```php
protected function resolveEffectiveTtl(): int
{
    if ($this->onceTtl !== null) {
        return $this->onceTtl;
    }

    $modelTtls = config('repository-cache.model_ttl', []);
    $table     = $this->getCachePrefix();

    return $modelTtls[$table] ?? $this->ttl;
}
```

Replace `$this->onceTtl ?? $this->ttl` in `cached()` with `$this->resolveEffectiveTtl()`.

---

### 2.4 `BaseRepositoryCache` Constructor Ignores Config TTL Default

**File:** `src/BaseRepositoryCache.php:52`

**Problem:** The constructor hard-codes `$ttl = 3600`. It should default to `config('repository-cache.ttl', 3600)` so the global config is honoured when no explicit TTL is passed.

```php
// Current — hardcoded
public function __construct(
    protected RepositoryContract $repository,
    protected int $ttl = 3600,
    ...
)

// Fix — read config
public function __construct(
    protected RepositoryContract $repository,
    int $ttl = 0,
    protected ?string $driver = null,
    protected ?string $prefix = null,
) {
    $this->ttl = $ttl ?: (int) config('repository-cache.ttl', 3600);
}
```

---

## 3. Contract / Type Inconsistencies

### 3.1 `QueryOptions` DTO Not Accepted by Cache Layer or Contracts

**Files:** `src/BaseRepositoryCache.php`, `src/Contracts/Concerns/Readable.php`

**Problem:** `BaseRepository` correctly accepts `array|QueryOptions $options` as a union type. But:

1. `BaseRepositoryCache` only declares `array $options` — passing a `QueryOptions` to the cache layer causes a type error.
2. The `Readable` contract also only declares `array $options` — so static analysis tools will reject `QueryOptions` at the call site even when targeting the concrete class.

**Fix — update `Readable` contract signatures:**

```php
use Frontier\Repositories\ValueObjects\QueryOptions;

public function retrieve(array $columns = ['*'], array|QueryOptions $options = []): Collection;
public function retrieveBy(array $conditions, array $columns = ['*'], array|QueryOptions $options = []): Collection;
// ... all other retrieve* methods
```

**Fix — add resolver helper in `BaseRepositoryCache`:**

```php
private function resolveOptionsArray(array|QueryOptions $options): array
{
    return $options instanceof QueryOptions ? $options->toArray() : $options;
}

public function retrieve(array $columns = ['*'], array|QueryOptions $options = []): Collection
{
    $opts = $this->resolveOptionsArray($options);

    return $this->cached(
        'retrieve',
        ['columns' => $columns, 'options' => $opts],
        fn () => $this->repository->retrieve($columns, $opts)
    );
}
```

---

### 3.2 `cacheFor()` Is Missing From the `RepositoryCache` Contract

**File:** `src/Contracts/RepositoryCache.php`

**Problem:** `BaseRepositoryCache` has a public `cacheFor(int $seconds): static` method but it is **not declared in the `RepositoryCache` contract**. Code that type-hints `RepositoryCache` cannot call `cacheFor()` without an unsafe cast.

**Fix — add to contract:**

```php
interface RepositoryCache
{
    // ... existing methods

    /**
     * Override the TTL for the next read operation only.
     * Resets automatically after the next cached() call.
     */
    public function cacheFor(int $seconds): static;
}
```

---

### 3.3 Restore Operations Are Semantically Misplaced in `Deletable`

**File:** `src/Contracts/Concerns/Deletable.php:194–231`

**Problem:** `restore()` and `restoreById()` live inside `Deletable` under a "Soft-Delete Restore Operations" section. Restore is the **opposite** of delete — grouping them violates SRP and misleads callers. Code that type-hints `Deletable` for a write-guard service would unexpectedly also gain restore capability.

**Fix — new `Restorable` concern:**

```php
// src/Contracts/Concerns/Restorable.php
namespace Frontier\Repositories\Contracts\Concerns;

interface Restorable
{
    /**
     * Restore soft-deleted records matching conditions.
     *
     * @param  array<string, mixed>  $conditions
     */
    public function restore(array $conditions): int;

    /**
     * Restore a single soft-deleted record by its primary key.
     *
     * @param  int|string  $id
     */
    public function restoreById(int|string $id): bool;
}
```

Update the composite `Repository` interface:

```php
interface Repository extends Creatable, Deletable, Readable, Restorable, RepositoryUtility, Updatable {}
```

---

## 4. Performance Improvements

### 4.1 `shouldCache()` and `getCacheDriver()` Call `config()` on Every Read

**File:** `src/BaseRepositoryCache.php:87, 80`

**Problem:** Both methods invoke `config()` on every read operation (`retrieve*`, `find*`, `count`, `exists`). In high-throughput APIs this is a measurable overhead.

```php
public function shouldCache(): bool
{
    return ! $this->skipCache && config('repository-cache.enabled', true);  // per call
}

public function getCacheDriver(): ?string
{
    return $this->driver ?? config('repository-cache.driver');  // per call
}
```

**Fix — resolve at construction time:**

```php
private bool $globalCacheEnabled;

public function __construct(
    protected RepositoryContract $repository,
    int $ttl = 0,
    ?string $driver = null,
    protected ?string $prefix = null,
) {
    $this->ttl                = $ttl ?: (int) config('repository-cache.ttl', 3600);
    $this->driver             = $driver ?? config('repository-cache.driver');
    $this->globalCacheEnabled = (bool) config('repository-cache.enabled', true);
}

public function shouldCache(): bool
{
    return ! $this->skipCache && $this->globalCacheEnabled;
}

public function getCacheDriver(): ?string
{
    return $this->driver;
}
```

---

### 4.2 `store()` Resolves the Cache Store Instance on Every Call

**File:** `src/BaseRepositoryCache.php:736`

**Problem:** `store()` calls `Cache::store($this->getCacheDriver())` and optionally wraps it with `tags()` on every single read invocation. The store instance is stable for the lifetime of the request and can be safely memoized.

```php
protected function store(): CacheContract
{
    $store = Cache::store($this->getCacheDriver());  // IoC resolution per call

    return $store->supportsTags() ? $store->tags([$this->getCachePrefix()]) : $store;
}
```

**Fix — memoize:**

```php
private ?CacheContract $resolvedStore = null;

protected function store(): CacheContract
{
    if ($this->resolvedStore === null) {
        $store = Cache::store($this->getCacheDriver());
        $this->resolvedStore = $store->supportsTags()
            ? $store->tags([$this->getCachePrefix()])
            : $store;
    }

    return $this->resolvedStore;
}
```

---

### 4.3 `deleteByIds()` Sends a Single Unbounded `WHERE IN` for Large ID Arrays

**File:** `src/BaseRepository.php:610`

**Problem:** `deleteByIds()` runs a single `WHERE id IN (...)` containing all provided IDs. For thousands of IDs this can exceed MySQL's `max_allowed_packet` size or cause sub-optimal query plans.

**Fix — chunk the ID array:**

```php
public function deleteByIds(array $ids, int $chunkSize = 500): int
{
    return $this->transaction(function () use ($ids, $chunkSize): int {
        $deleted = 0;

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            $this->newQuery()
                ->whereIn($this->model->getKeyName(), $chunk)
                ->cursor()
                ->each(function (Model $model) use (&$deleted): void {
                    $model->delete();
                    $deleted++;
                });
        }

        return $deleted;
    });
}
```

---

## 5. Missing Methods & Features

### 5.1 `chunkById()` — More Reliable Chunking for Concurrent Mutations

**Problem:** `chunk()` uses `LIMIT/OFFSET` pagination. When records are deleted or inserted mid-iteration, rows shift position causing records to be skipped or duplicated. Laravel's `chunkById()` uses keyset pagination on the primary key which is immune to this.

**Add to `RepositoryUtility` contract and `BaseRepository`:**

```php
// Contract
/**
 * Process records in chunks using keyset (cursor) pagination on the primary key.
 *
 * Safer than chunk() when records may be mutated during iteration.
 *
 * @param  int  $count  Records per chunk
 * @param  callable  $callback  Callback receiving each chunk
 * @param  string|null  $column  Column to use for keyset pagination (default: primary key)
 */
public function chunkById(int $count, callable $callback, ?string $column = null): bool;

// BaseRepository
public function chunkById(int $count, callable $callback, ?string $column = null): bool
{
    return $this->newQuery()->chunkById($count, $callback, $column);
}
```

---

### 5.2 `lazy()` / `lazyById()` — Memory-Efficient Record Streaming

**Problem:** `chunk()` loads a full collection per chunk into memory. `lazy()` returns a `LazyCollection` that streams one record at a time via PHP generators — ideal for large export jobs or batch processors where only one record is needed at a time.

**Add to `RepositoryUtility` contract and `BaseRepository`:**

```php
use Illuminate\Support\LazyCollection;

// Contract
/**
 * Stream records lazily using a LazyCollection.
 *
 * More memory-efficient than chunk() — yields one record at a time.
 *
 * @param  int  $chunkSize  Records fetched per underlying database query
 */
public function lazy(int $chunkSize = 1000): LazyCollection;

/**
 * Stream records lazily using keyset pagination on the primary key.
 */
public function lazyById(int $chunkSize = 1000, ?string $column = null): LazyCollection;

// BaseRepository
public function lazy(int $chunkSize = 1000): LazyCollection
{
    return $this->newQuery()->lazy($chunkSize);
}

public function lazyById(int $chunkSize = 1000, ?string $column = null): LazyCollection
{
    return $this->newQuery()->lazyById($chunkSize, $column ?? $this->model->getKeyName());
}
```

---

### 5.3 `Aggregatable` Concern — Missing `sum()`, `avg()`, `min()`, `max()`

**Problem:** The package only exposes `count()` and `exists()` for aggregation. Production apps routinely need `sum()`, `avg()`, `min()`, and `max()` for dashboards, reports, and financial calculations. Without them, callers must reach around the repository into raw query builders, defeating the abstraction.

**New interface `src/Contracts/Concerns/Aggregatable.php`:**

```php
namespace Frontier\Repositories\Contracts\Concerns;

interface Aggregatable
{
    /**
     * Sum a column for records matching conditions.
     *
     * @param  string  $column  Column to sum
     * @param  array<string, mixed>  $conditions
     */
    public function sum(string $column, array $conditions = []): int|float;

    /**
     * Average a column for records matching conditions.
     *
     * @param  string  $column
     * @param  array<string, mixed>  $conditions
     */
    public function avg(string $column, array $conditions = []): int|float|null;

    /**
     * Minimum value of a column.
     *
     * @param  string  $column
     * @param  array<string, mixed>  $conditions
     */
    public function min(string $column, array $conditions = []): mixed;

    /**
     * Maximum value of a column.
     *
     * @param  string  $column
     * @param  array<string, mixed>  $conditions
     */
    public function max(string $column, array $conditions = []): mixed;
}
```

**`BaseRepository` implementation:**

```php
public function sum(string $column, array $conditions = []): int|float
{
    $query = $this->newQuery();
    if (! empty($conditions)) {
        $query->where($conditions);
    }
    return $query->sum($this->prefixColumn($column));
}
// avg, min, max follow same pattern
```

**`BaseRepositoryCache` caching:**

```php
public function sum(string $column, array $conditions = []): int|float
{
    return $this->cached(
        'sum',
        ['column' => $column, 'conditions' => $conditions],
        fn () => $this->repository->sum($column, $conditions)
    );
}
```

Compose into `Repository`:

```php
interface Repository extends Aggregatable, Creatable, Deletable, Readable, Restorable, RepositoryUtility, Updatable {}
```

---

### 5.4 `findByIdsOrFail()` — Missing from Contract and Implementation

**Problem:** `findByIds()` silently omits missing IDs. Most `*ById` variants have an `*OrFail` counterpart, but `findByIdsOrFail()` is entirely absent. Callers who need strict existence checking for a set of IDs must manually verify the returned collection count.

**Add to `Readable` contract and `BaseRepository`:**

```php
// Contract
/**
 * Find multiple records by their primary keys or throw if any are missing.
 *
 * @param  array<int, int|string>  $ids
 * @param  array<int, string>  $columns
 * @return Collection<int, Model>
 *
 * @throws ModelNotFoundException When one or more IDs are not found
 */
public function findByIdsOrFail(array $ids, array $columns = ['*']): Collection;

// BaseRepository
public function findByIdsOrFail(array $ids, array $columns = ['*']): Collection
{
    $models = $this->findByIds($ids, $columns);

    if ($models->count() !== count(array_unique($ids))) {
        throw (new ModelNotFoundException)->setModel($this->model::class, $ids);
    }

    return $models;
}
```

---

### 5.5 `QueryOptions` Missing `withTrashed` / `onlyTrashed` Flags

**File:** `src/ValueObjects/QueryOptions.php`

**Problem:** `QueryOptions` has no way to express soft-delete scope inclusion. Any retrieval on a soft-deleted model requires falling back to raw `scopes` arrays (`['scopes' => ['withTrashed']]`), losing the DTO's type-safety advantage.

**Add to `QueryOptions`:**

```php
public function __construct(
    // ... existing params
    public readonly bool $withTrashed = false,
    public readonly bool $onlyTrashed = false,
) {}

public function toArray(): array
{
    $options = [/* existing mappings */];

    if ($this->withTrashed) {
        $options['with_trashed'] = true;
    }
    if ($this->onlyTrashed) {
        $options['only_trashed'] = true;
    }

    return $options;
}
```

Handle in `Retrievable::applyQueryOptions()`:

```php
if (Arr::get($options, 'with_trashed', false)) {
    $query->withTrashed();
}
if (Arr::get($options, 'only_trashed', false)) {
    $query->onlyTrashed();
}
```

---

## 6. Architecture Improvements — New Concepts

### 6.1 `CriteriaInterface` / Specification Pattern — Typed, Reusable Query Conditions

**Problem:** All query conditions are raw `array<string, mixed>` — no type checking, no IDE autocompletion, no reusability. Teams duplicate condition arrays across services and tests with no single source of truth for domain rules like "an active user" or "an expired subscription."

**New `src/Contracts/Criteria.php`:**

```php
namespace Frontier\Repositories\Contracts;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * A self-contained, reusable query condition (Specification pattern).
 *
 * Encapsulates a WHERE clause or scope invocation as an object,
 * enabling type-safe, composable, and individually testable query building.
 *
 * @example
 * ```php
 * class ActiveUsersCriteria implements Criteria
 * {
 *     public function apply(Builder $query): Builder
 *     {
 *         return $query->where('status', 'active')
 *                      ->whereNotNull('email_verified_at');
 *     }
 * }
 *
 * $users = $repository->withCriteria([new ActiveUsersCriteria])->retrieve();
 * ```
 */
interface Criteria
{
    public function apply(Builder $query): Builder;
}
```

**Add `withCriteria()` to `RepositoryUtility` contract:**

```php
/**
 * Apply one or more Criteria to the next query only.
 *
 * @param  array<int, Criteria>  $criteria
 */
public function withCriteria(array $criteria): static;
```

**Implementation in `BaseRepository`:**

```php
/** @var array<int, Criteria> */
private array $pendingCriteria = [];

public function withCriteria(array $criteria): static
{
    $this->pendingCriteria = $criteria;

    return $this;
}

public function newQuery(): Builder
{
    $base = $this->withBuilder instanceof Builder
        ? $this->withBuilder->clone()
        : $this->model->newQuery();

    foreach ($this->pendingCriteria as $criterion) {
        $base = $criterion->apply($base);
    }

    $this->pendingCriteria = [];  // consumed after one use

    return $base;
}
```

**Usage:**

```php
class ActiveCriteria implements Criteria {
    public function apply(Builder $q): Builder {
        return $q->where('status', 'active');
    }
}

class VerifiedEmailCriteria implements Criteria {
    public function apply(Builder $q): Builder {
        return $q->whereNotNull('email_verified_at');
    }
}

// Composable, reusable, individually testable
$users = $userRepository
    ->withCriteria([new ActiveCriteria, new VerifiedEmailCriteria])
    ->retrievePaginate(perPage: 15);
```

---

### 6.2 Repository Observer System — Hooks for Observability & Domain Events

**Problem:** There is no hook into repository operations. Production systems commonly need: audit logging, dispatching domain events after a write, metrics collection, or webhook emission. Currently callers must wrap every repository call in their own delegation layer.

**New `src/Contracts/RepositoryObserver.php`:**

```php
namespace Frontier\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Observer interface for repository lifecycle events.
 *
 * Register via $repository->observe(new MyObserver()) to hook into
 * read/write operations for logging, auditing, metrics, or domain events.
 */
interface RepositoryObserver
{
    public function afterCreate(Model $model): void;

    public function afterUpdate(array $conditions, array $values, int|Model|null $result): void;

    public function afterDelete(array $conditions, int|bool $result): void;

    public function afterFind(array $conditions, ?Model $result): void;

    public function afterRetrieve(Collection $result): void;
}
```

**Add observer registration to `BaseRepository`:**

```php
/** @var array<int, RepositoryObserver> */
private array $observers = [];

public function observe(RepositoryObserver $observer): static
{
    $this->observers[] = $observer;

    return $this;
}

public function create(array $values): Model
{
    $model = $this->newQuery()->create($values);

    foreach ($this->observers as $observer) {
        $observer->afterCreate($model);
    }

    return $model;
}
```

**Example observer:**

```php
class AuditObserver implements RepositoryObserver
{
    public function afterCreate(Model $model): void
    {
        Log::info('Repository created', [
            'model' => $model::class,
            'id'    => $model->getKey(),
        ]);
    }
    // ... other hooks
}

$userRepository->observe(new AuditObserver);
```

---

### 6.3 `RepositoryPipeline` — Composable Decorator Chain

**Problem:** Currently there is only one decorator (`BaseRepositoryCache`). Production systems often need multiple cross-cutting concerns stacked: caching AND audit logging AND metrics collection. Nesting decorators manually is verbose and error-prone.

**New `src/RepositoryPipeline.php`:**

```php
namespace Frontier\Repositories;

use Frontier\Repositories\Contracts\Repository;

/**
 * Fluent builder for composing repository decorator chains.
 *
 * @example
 * ```php
 * $repository = RepositoryPipeline::for(new UserRepository(new User))
 *     ->through(new UserRepositoryCache(...))
 *     ->through(new LoggingRepositoryDecorator(...))
 *     ->build();
 * ```
 */
final class RepositoryPipeline
{
    /** @var array<int, callable(Repository): Repository> */
    private array $decorators = [];

    private function __construct(private readonly Repository $base) {}

    public static function for(Repository $base): static
    {
        return new static($base);
    }

    /**
     * Add a decorator factory. The decorator receives the current repository
     * and returns a new repository wrapping it.
     *
     * @param  callable(Repository): Repository  $decorator
     */
    public function through(callable $decorator): static
    {
        $this->decorators[] = $decorator;

        return $this;
    }

    public function build(): Repository
    {
        return array_reduce(
            $this->decorators,
            fn (Repository $carry, callable $decorator): Repository => $decorator($carry),
            $this->base
        );
    }
}
```

**Service provider usage:**

```php
$this->app->bind(UserRepositoryInterface::class, function ($app) {
    return RepositoryPipeline::for($app->make(UserRepository::class))
        ->through(fn ($repo) => new UserRepositoryCache($repo))
        ->through(fn ($repo) => new LoggingRepositoryDecorator($repo))
        ->build();
});
```

---

### 6.4 `QueryOptions` Static Factory Methods — Fluent Builder API

**File:** `src/ValueObjects/QueryOptions.php`

**Problem:** `QueryOptions` is a readonly DTO — all options must be declared up front. Building it conditionally (e.g., add a sort only when a request parameter is present) requires awkward array construction before instantiation. Static factories and wither methods make the API fluent and self-documenting.

**Add to `QueryOptions`:**

```php
final class QueryOptions
{
    // ... existing constructor

    /**
     * Named constructor — start with sort.
     */
    public static function sortBy(string|array $column, string|array $direction = 'asc'): static
    {
        return new static(sort: $column, direction: $direction);
    }

    /**
     * Named constructor — start with eager loads.
     */
    public static function with(array $relations): static
    {
        return new static(with: $relations);
    }

    /**
     * Return a new instance with a different sort applied (immutable wither).
     */
    public function withSort(string|array $column, string|array $direction = 'asc'): static
    {
        return new static(
            filters:   $this->filters,
            scopes:    $this->scopes,
            joins:     $this->joins,
            sort:      $column,
            direction: $direction,
            groupBy:   $this->groupBy,
            with:      $this->with,
            withCount: $this->withCount,
            distinct:  $this->distinct,
            limit:     $this->limit,
            offset:    $this->offset,
        );
    }

    /**
     * Return a new instance with eager loads merged.
     */
    public function withRelations(array $relations): static
    {
        return new static(
            // ... all existing props
            with: array_merge($this->with, $relations),
        );
    }
}

// Fluent usage:
$options = QueryOptions::sortBy('created_at', 'desc')
    ->withRelations(['profile', 'roles']);
```

---

### 6.5 `LoggingRepositoryDecorator` — Built-in Instrumentation Decorator

**Problem:** There is no built-in way to log or time queries for debugging in staging environments. Teams build their own wrappers.

**New `src/LoggingRepositoryDecorator.php`:**

```php
namespace Frontier\Repositories;

use Frontier\Repositories\Contracts\Repository as RepositoryContract;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Repository decorator that emits structured log entries for every operation.
 *
 * Wraps any repository and logs method name, table, parameters, result count/type,
 * and duration in milliseconds. Useful for debugging and performance profiling.
 *
 * @example
 * ```php
 * $repo = new LoggingRepositoryDecorator(
 *     repository: new UserRepository(new User),
 *     channel: 'repository',
 *     level: 'debug',
 * );
 * ```
 */
class LoggingRepositoryDecorator implements RepositoryContract
{
    public function __construct(
        protected RepositoryContract $repository,
        private readonly string $channel = 'stack',
        private readonly string $level = 'debug',
    ) {}

    private function measured(string $method, array $context, callable $fn): mixed
    {
        $start  = hrtime(true);
        $result = $fn();
        $ms     = round((hrtime(true) - $start) / 1e6, 2);

        Log::channel($this->channel)->{$this->level}("Repository::{$method}", array_merge($context, [
            'table'       => $this->getTable(),
            'duration_ms' => $ms,
        ]));

        return $result;
    }

    public function find(array $conditions, array $columns = ['*']): ?Model
    {
        return $this->measured('find', ['conditions' => $conditions], fn () => $this->repository->find($conditions, $columns));
    }

    // ... wrap all methods using measured()
}
```

---

## 7. Test Coverage Gaps

The current test suite is critically thin for a production package:

- `BaseRepositoryTest` — **1 test** (only checks interface implementation)
- `RepositoryCacheTest` — **4 tests** (basic caching + clearCache)
- All CRUD operations: **0 tests**
- All pagination methods: **0 tests**
- `QueryOptions` DTO: **0 tests**
- Actions directory: **0 tests**

### 7.1 `BaseRepository` — Missing Test Scenarios

| Method / Scenario | Status |
|---|---|
| `create()` — returns model, fills attributes | Missing |
| `createMany()` — returns collection, all created | Missing |
| `insertMany()` — chunked, returns bool | Missing |
| `find()` — found / not found | Missing |
| `findOrFail()` — found / not found (throws) | Missing |
| `findById()` — found / not found | Missing |
| `findByIdOrFail()` — throws `ModelNotFoundException` | Missing |
| `findByIds()` — partial matches omitted | Missing |
| `findByOr()` — OR logic applied | Missing |
| `retrieve()` with all option combinations | Missing |
| `retrieveBy()` / `retrieveByOr()` | Missing |
| `retrievePaginate()` / `retrieveByPaginate()` | Missing |
| `retrieveSimplePaginate()` / `retrieveCursorPaginate()` | Missing |
| `update()` — returns int affected rows | Missing |
| `updateOrFail()` — throws when 0 affected | Missing |
| `updateBy()` — model-level, returns collection | Missing |
| `updateById()` — not found returns null | Missing |
| `updateByIdOrFail()` — throws | Missing |
| `delete()` — returns int affected rows | Missing |
| `deleteOrFail()` — throws when 0 deleted | Missing |
| `deleteBy()` — model-level, returns collection | Missing |
| `deleteById()` — not found returns false | Missing |
| `deleteByIds()` — uses cursor, wrapped in transaction | Missing |
| `restore()` / `restoreById()` | Missing |
| `count()` with / without conditions | Missing |
| `exists()` | Missing |
| `insert()` / `insertGetId()` / `upsert()` | Missing |
| `firstOrCreate()` / `updateOrCreate()` | Missing |
| `chunk()` / `transaction()` | Missing |
| `withBuilder()` — cloned builder applied to queries | Missing |
| `resetBuilder()` — actually clears `withBuilder` state | Missing |

### 7.2 `BaseRepositoryCache` — Missing Test Scenarios

| Scenario | Status |
|---|---|
| `withoutCache()` bypasses cache for one call | Missing |
| `refreshCache()` forgets key then re-caches | Missing |
| `cacheFor(30)` uses 30s TTL then resets to default | Missing |
| `findById()`, `count()`, `exists()` are cached | Missing |
| All paginate methods are cached | Missing |
| Flag state resets after exception in callback | Missing |
| Same params produce identical cache key | Missing |
| Different params produce different cache keys | Missing |
| Closure fingerprinting makes keys stable | Missing |
| `cache_empty_results=false` skips caching null | Missing |
| `excluded_methods` bypasses cache | Missing |
| `model_ttl` override applied for matching table | Missing |
| `firstOrCreate` does NOT clear cache when record found | Missing |
| `updateOrCreate` clears only when record actually changed | Missing |
| `withBuilder()` on cache decorator clears cache | Missing |

### 7.3 `QueryOptions` — Missing Tests

| Scenario | Status |
|---|---|
| `toArray()` omits null/empty values | Missing |
| `toArray()` includes all set values with correct keys | Missing |
| `group_by` maps to `group_by` key | Missing |
| `withCount` maps to `with_count` key | Missing |
| `withTrashed` / `onlyTrashed` flags | Missing |
| Round-trip: `QueryOptions` → `toArray()` → applied to query builder | Missing |

### 7.4 Suggested Test Structure

```
tests/
├── Unit/
│   ├── BaseRepositoryTest.php             ← expand
│   ├── BaseRepositoryCacheTest.php        ← expand
│   ├── QueryOptionsTest.php               ← new
│   ├── RetrievableTest.php                ← exists, good
│   └── RetrievableSortTest.php            ← exists, good
├── Feature/
│   ├── MakeRepositoryCommandTest.php      ← exists
│   ├── MakeRepositoryCacheCommandTest.php ← new
│   ├── MakeRepositoryInterfaceCommandTest.php ← new
│   └── MakeRepositoryActionCommandTest.php    ← new
└── Architecture/
    └── StrictTypesTest.php                ← verify all files have declare(strict_types=1)
```

---

## 8. Code Style & Maintainability

### 8.1 `BaseRepositoryCache` Has 35+ Boilerplate Delegation Methods

**Problem:** Every method in `BaseRepositoryCache` follows one of two identical patterns (read → `cached()`, write → `tap()` + `clearCache()`). Adding a new method to `BaseRepository` requires also adding it to `BaseRepositoryCache` — a silent failure mode that is easy to miss.

**Improvement:** Add a `__call` safety net so any undeclared method falls through to the inner repo (with caching for reads by convention):

```php
/**
 * Safety fallback: proxy any call not explicitly declared.
 * All public methods should still be explicitly declared for IDE support.
 */
public function __call(string $method, array $arguments): mixed
{
    if (! method_exists($this->repository, $method)) {
        throw new \BadMethodCallException("Method {$method} does not exist on the inner repository.");
    }

    return $this->cached($method, $arguments, fn () => $this->repository->{$method}(...$arguments));
}
```

This is a safety net, not a replacement for explicit declarations.

---

### 8.2 `BaseRepository::resolveOptions()` Should Be `protected`

**File:** `src/BaseRepository.php:252`

`resolveOptions()` is `private` but child repositories implementing custom retrieve methods need it to resolve `QueryOptions` DTOs in the same way. It should be `protected`.

```php
// Current
private function resolveOptions(array|QueryOptions $options): array

// Fix
protected function resolveOptions(array|QueryOptions $options): array
```

---

### 8.3 `GeneratorCommand` Hard-Codes Config Keys in Three Places

**File:** `src/Console/Commands/GeneratorCommand.php:70, 117, 186, 205`

`config('app-modules.modules_directory', 'app-modules')` and `config('app-modules.modules_namespace', 'Modules')` are referenced in multiple places. Extract to overridable methods:

```php
protected function getModulesDirectory(): string
{
    return config('app-modules.modules_directory', 'app-modules');
}

protected function getModulesNamespace(): string
{
    return config('app-modules.modules_namespace', 'Modules');
}
```

This also makes the commands testable without setting app config.

---

### 8.4 `stub/repository-cache.stub` Uses `$CLASS_NAME_BASE$` Without Documentation

**File:** `stubs/repository-cache.stub:18`

The stub uses `$CLASS_NAME_BASE$` but the variable is not listed alongside `CLASS_NAME` and `NAMESPACE` in the generator's `getStubVariables()` return value documentation. Add a comment to `MakeRepositoryCache::getStubVariables()` listing all variables it injects.

---

## 9. Priority Matrix

| # | Issue | Impact | Effort | Priority |
|---|-------|--------|--------|----------|
| 1.4 | Actions missing `declare(strict_types=1)` | Medium | Low | **P0** |
| 1.1 | `resetBuilder()` no-op — cannot clear `withBuilder()` | High | Low | **P0** |
| 2.1 | `cache_empty_results` defined but never checked | High | Medium | **P0** |
| 2.2 | `excluded_methods` defined but never checked | High | Low | **P0** |
| 2.3 | `model_ttl` defined but never applied | High | Low | **P0** |
| 2.4 | Constructor ignores config TTL | High | Low | **P0** |
| 3.1 | `QueryOptions` not accepted by cache layer or contracts | High | Medium | **P0** |
| 3.2 | `cacheFor()` missing from `RepositoryCache` contract | Medium | Trivial | **P0** |
| 1.2 | `firstOrCreate`/`updateOrCreate` aggressively clear cache | Medium | Low | **P1** |
| 1.3 | `withBuilder()` on cache does not invalidate cache | Medium | Low | **P1** |
| 4.1 | `config()` called per read — resolve at construction | Medium | Low | **P1** |
| 4.2 | `store()` recreates cache store per call | Low | Low | **P1** |
| 5.4 | `findByIdsOrFail()` missing | Medium | Low | **P1** |
| 7.x | Critically thin test coverage across all CRUD | Critical | High | **P1** |
| 3.3 | `restore*` semantically wrong in `Deletable` | Low | Low | **P2** |
| 5.1 | `chunkById()` missing | Medium | Low | **P2** |
| 5.2 | `lazy()` / `lazyById()` missing | Medium | Low | **P2** |
| 5.3 | `Aggregatable` concern (`sum/avg/min/max`) | High | Medium | **P2** |
| 5.5 | `QueryOptions` missing `withTrashed` / `onlyTrashed` | Medium | Low | **P2** |
| 4.3 | `deleteByIds()` unbounded `WHERE IN` | Low | Low | **P2** |
| 6.1 | Criteria / Specification pattern | High | High | **P3** |
| 6.2 | Repository observer / event hooks | High | Medium | **P3** |
| 6.3 | `RepositoryPipeline` decorator composer | Medium | Medium | **P3** |
| 6.4 | `QueryOptions` static factory & wither methods | Medium | Low | **P3** |
| 6.5 | `LoggingRepositoryDecorator` | Low | Medium | **P3** |
| 8.2 | `resolveOptions()` should be `protected` | Low | Trivial | **P3** |
| 8.3 | `GeneratorCommand` hard-coded config keys | Low | Low | **P3** |

---

## Summary

The package has a solid foundation — the ISP-split contracts are clean, the `QueryOptions` DTO is a welcome abstraction, and the security safeguards in `Retrievable` (column validation, raw expression blocking) are well-considered. The most impactful issues to address are:

1. **Three config keys shipped and documented but never read** (`cache_empty_results`, `excluded_methods`, `model_ttl`) — these directly erode trust in the package.
2. **Type inconsistency between the cache layer and contracts** — `QueryOptions` passing fails silently at the cache boundary.
3. **The no-op `resetBuilder()`** — a real bug for any shared repository instance where `withBuilder()` is called.
4. **Critically thin test coverage** — a production package must exercise all CRUD paths, cache invalidation strategies, and edge cases.
5. **Missing `Aggregatable` concern** — without `sum/avg/min/max`, callers will bypass the repository abstraction for common reporting needs.

The new concepts proposed (Criteria/Specification pattern, Observer hooks, Pipeline decorator, `QueryOptions` fluent factory) would elevate this from a useful scaffold to a genuinely production-grade, extensible data-access layer capable of supporting complex enterprise client requirements.
