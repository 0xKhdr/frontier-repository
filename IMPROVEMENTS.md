# Frontier Repository — Improvement Proposals

> **Status**: Proposed — ready for implementation
> **Audit Date**: 2026-03-04
> **Package**: `frontier/repository` v2.x
> **Scope**: Performance, reliability, architecture, developer experience, and test coverage

This document is the output of a comprehensive codebase audit. Each entry describes a specific gap found in the current implementation and provides a concrete, ready-to-implement solution with code examples. Items are grouped by category and ordered by priority within each group.

---

## Table of Contents

1. [Performance](#1-performance)
2. [Reliability & Code Quality](#2-reliability--code-quality)
3. [Architecture & New Concepts](#3-architecture--new-concepts)
4. [Configuration Enhancements](#4-configuration-enhancements)
5. [Developer Experience](#5-developer-experience)
6. [Test Coverage](#6-test-coverage)
7. [Implementation Roadmap](#7-implementation-roadmap)

---

## 1. Performance

### P1 · `createMany()` N+1 Query Problem

**Priority**: Critical
**File**: `src/BaseRepository.php:71-81`

**Problem**
`createMany()` calls `$query->create($record)` inside a `foreach` loop. For N records this produces N individual `INSERT` queries, and no transaction wraps them — a partial failure leaves the database in an inconsistent state.

```php
// Current implementation — N queries, no atomicity guarantee
foreach ($records as $record) {
    $models->push($query->create($record));
}
```

**Solution**

**(a) Wrap `createMany()` in a transaction** so all inserts succeed or none do:

```php
public function createMany(array $records): Collection
{
    return $this->transaction(function () use ($records): Collection {
        $models = new Collection;
        $query  = $this->newQuery();

        foreach ($records as $record) {
            $models->push($query->create($record));
        }

        return $models;
    });
}
```

**(b) Add a new high-performance `insertMany()` method** that bypasses Eloquent model instantiation entirely (no events, no casts), using chunked bulk `INSERT` statements — ideal for large seed/import operations:

```php
/**
 * Insert multiple records without firing model events.
 *
 * Uses chunked bulk INSERT for maximum performance.
 * No Eloquent lifecycle (creating/created events, mutators, casts) is triggered.
 * Prefer createMany() when you need model events; use this for bulk data loads.
 *
 * @param  array<int, array<string, mixed>>  $records
 * @param  int  $chunkSize  Number of rows per INSERT statement (default 500)
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
```

**Impact**: Prevents data loss on partial failures; `insertMany()` reduces 1 000 inserts from 1 000 queries to ~2 queries.

---

### P2 · `updateById()` — Intentional Double Query (Document Trade-off)

**Priority**: High
**File**: `src/BaseRepository.php:336-347`

**Problem**
`updateById()` issues two queries: `findById()` (SELECT) followed by `$model->update()` (UPDATE). This is intentional — it ensures Eloquent model events (`updating`, `updated`), attribute casts, and mutators all fire — but the trade-off is not documented.

**Solution**

Add a complementary `updateByIdQuery()` that issues a single-query UPDATE for callers that don't need model lifecycle events:

```php
/**
 * Update a record by its primary key using a single query.
 *
 * Bypasses Eloquent model instantiation — no events (updating/updated),
 * casts, or mutators are triggered. Faster than updateById() for bulk
 * operations where lifecycle is not required.
 *
 * @param  int|string  $id
 * @param  array<string, mixed>  $values
 * @return int  Number of affected rows (0 if not found)
 */
public function updateByIdQuery(int|string $id, array $values): int
{
    return $this->newQuery()
        ->where($this->model->getKeyName(), $id)
        ->update($values);
}
```

Also add a PHPDoc note to `updateById()` to document why two queries are used:

```php
/**
 * Update a record by its primary key.
 *
 * Issues two queries (SELECT + UPDATE) to ensure Eloquent model events
 * (updating/updated), attribute casts, and mutators are all triggered.
 * Use updateByIdQuery() for a single-query alternative when lifecycle is not needed.
 */
public function updateById(int|string $id, array $values): ?Model { ... }
```

---

### P3 · Missing `findByIds()` Method

**Priority**: High
**File**: `src/BaseRepository.php`, `src/Contracts/Concerns/Readable.php`

**Problem**
There is no dedicated method for fetching multiple records by their primary keys. Callers must write:

```php
$repo->retrieveBy([], ['*'], ['scopes' => [...]])
// or drop to raw builder
```

This is a very common operation and deserves a first-class API.

**Solution**

Add `findByIds()` to both the interface and the implementation:

```php
// src/Contracts/Concerns/Readable.php
/**
 * Find multiple records by their primary keys.
 *
 * Returns only found records — silently omits missing IDs.
 * Order of results follows database ordering, not the input array order.
 *
 * @param  array<int, int|string>  $ids
 * @param  array<int, string>  $columns
 * @return Collection<int, Model>
 */
public function findByIds(array $ids, array $columns = ['*']): Collection;

// src/BaseRepository.php
public function findByIds(array $ids, array $columns = ['*']): Collection
{
    return $this->newQuery()
        ->select($this->prefixColumns($columns))
        ->whereIn($this->model->getKeyName(), $ids)
        ->get();
}
```

**Cache decorator** (`src/BaseRepositoryCache.php`):

```php
public function findByIds(array $ids, array $columns = ['*']): Collection
{
    return $this->cached(
        'findByIds',
        ['ids' => $ids, 'columns' => $columns],
        fn () => $this->repository->findByIds($ids, $columns)
    );
}
```

---

## 2. Reliability & Code Quality

### R1 · Cache Control Flags Not Exception-Safe

**Priority**: Critical
**File**: `src/BaseRepositoryCache.php:598-616`

**Problem**
`withoutCache()` sets `$skipCache = true`; `refreshCache()` sets `$forceRefresh = true`. Both are reset inside `cached()` — but only *after* the callback executes. If the callback throws an exception, `resetFlags()` is never called and the flags leak into the next call.

```php
// Current — flags reset after callback; exception leaves flags set
protected function cached(string $method, array $params, callable $callback): mixed
{
    ...
    $this->resetFlags(); // ← never reached if $callback() throws
    return $store->remember($key, $this->ttl, $callback);
}
```

**Solution**

Reset flags *before* the callback executes. Since flags are read before the callback is called, resetting them immediately after capture is safe:

```php
protected function cached(string $method, array $params, callable $callback): mixed
{
    if (! $this->shouldCache()) {
        $this->resetFlags();

        return $callback();
    }

    $key          = $this->key($method, $params);
    $store        = $this->store();
    $forceRefresh = $this->forceRefresh;

    $this->resetFlags(); // Reset before callback — exception-safe

    if ($forceRefresh) {
        $store->forget($key);
    }

    return $store->remember($key, $this->ttl, $callback);
}
```

**Impact**: Silent cache bypass on subsequent calls is eliminated.

---

### R2 · `BaseAction` Incomplete — No Constructor/Getter

**Priority**: High
**File**: `src/BaseAction.php`

**Problem**
`$repository` is declared as a typed property but is never initialised and has no getter. Subclasses have no guidance on how to inject the dependency, and accessing the uninitialised property throws a fatal error.

```php
// Current — property declared but never set
class BaseAction extends FrontierBaseAction
{
    protected Repository $repository;
}
```

**Solution**

Add a `repository()` accessor and improve the class docblock to guide users:

```php
/**
 * Base action class for repository-backed operations.
 *
 * Subclasses must inject a concrete repository in their constructor:
 *
 *   public function __construct(UserRepository $repository)
 *   {
 *       $this->repository = $repository;
 *   }
 *
 * Access the repository via $this->repository() inside handle().
 */
class BaseAction extends FrontierBaseAction
{
    protected Repository $repository;

    /**
     * Get the repository instance.
     */
    protected function repository(): Repository
    {
        return $this->repository;
    }
}
```

---

### R3 · Dynamic Scope Invocation Without Validation

**Priority**: High
**File**: `src/Traits/Retrievable.php:71-76`

**Problem**
Scope names from the `$options['scopes']` array are invoked as method calls directly on the query builder without validation. If scope names originate from user-controlled input (e.g., a query parameter), an attacker could invoke arbitrary query builder methods.

```php
// Current — no validation on $scope or $parameters
is_numeric($scope)
    ? $query->{$parameters}()
    : $query->{$scope}(...$parameters);
```

**Solution**

Add a `validateScopeName()` helper and call it before dynamic invocation:

```php
/**
 * Validate that a scope name is a safe PHP identifier.
 *
 * This prevents invocation of arbitrary query builder methods if scope
 * names are derived from untrusted sources.
 *
 * @throws InvalidArgumentException
 */
private function validateScopeName(string $name): void
{
    if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
        throw new InvalidArgumentException("Invalid scope name: {$name}");
    }
}

// Apply in applyQueryOptions():
if ($scopes = Arr::get($options, 'scopes')) {
    foreach ($scopes as $scope => $parameters) {
        if (is_numeric($scope)) {
            $this->validateScopeName((string) $parameters);
            $query->{$parameters}();
        } else {
            $this->validateScopeName($scope);
            $query->{$scope}(...$parameters);
        }
    }
}
```

Apply the same guard for the `joins` option block.

---

### R4 · `DANGEROUS_SQL_PATTERN` Blocks Legitimate SQL

**Priority**: Critical
**File**: `src/Traits/Retrievable.php:27`

**Problem**
The blocklist includes `union` and `create`, which are not injection-relevant in an `ORDER BY` context but prevent legitimate use cases:

- `CASE WHEN status = 'active' THEN 0 ELSE 1 END` — blocked because `create` contains `\bcreate\b`? No — but `CASE` is not in the list. The real blocker: `union` prevents `FIELD()` expressions that could appear alongside a `UNION` subquery in edge cases, and `create` is a false alarm risk.
- More concretely: any raw sort expression containing the substring word `union` (e.g. a column alias named `reunion_date` would be fine due to `\b` boundary, but `union all` in a subquery passed to `raw:` would be blocked — which is desirable). The issue is that `create` and `union` are valid in some `ORDER BY` expressions when using custom functions.

**Solution**

Remove `union` and `create` from the blocklist. They are not `ORDER BY`-specific injection keywords, and their presence causes false positives. Document the remaining keywords.

```php
/**
 * DDL/DML keywords that must not appear in raw ORDER BY or SELECT expressions.
 *
 * Blocked keywords and rationale:
 *   delete, update, insert — DML; can modify data
 *   drop, alter, truncate  — DDL; can destroy schema
 *   exec, execute          — stored procedure execution
 *   grant, revoke          — privilege escalation
 *
 * Intentionally NOT blocked:
 *   union  — valid in subquery expressions within ORDER BY (rarely used but legitimate)
 *   create — not dangerous in ORDER BY context; blocked 'created_at'-style false positives
 */
private const DANGEROUS_SQL_PATTERN = '/\b(delete|update|insert|drop|alter|truncate|exec|execute|grant|revoke)\b/i';
```

---

### R5 · Undocumented `app.default_order` Config Fallback

**Priority**: High
**File**: `src/Traits/Retrievable.php:101-102`

**Problem**
The query option resolution falls back to `config('app.default_order.sort')` and `config('app.default_order.direction')`. This config key does not exist in any Laravel default, is not documented in this package, and is not in `config/repository-cache.php`. A missing key silently returns `null` — hard to debug.

```php
// Current — undocumented external config dependency
Arr::get($options, 'sort') ?? config('app.default_order.sort'),
Arr::get($options, 'direction') ?? config('app.default_order.direction'),
```

**Solution**

Remove the undocumented fallback. If a default sort is desired it should be provided by the calling code or configured in `config/repository-cache.php`:

```php
// After — clean, no hidden dependencies
$this->applyOrder(
    $query,
    Arr::get($options, 'sort'),
    Arr::get($options, 'direction')
);
```

If default ordering is a desired package feature, add it explicitly to the config:

```php
// config/repository-cache.php
'default_sort'      => env('REPOSITORY_DEFAULT_SORT', null),
'default_direction' => env('REPOSITORY_DEFAULT_DIRECTION', 'asc'),
```

---

## 3. Architecture & New Concepts

### A1 · `QueryOptions` Value Object

**Priority**: Medium
**New file**: `src/ValueObjects/QueryOptions.php`

**Problem**
The `$options` array passed to `retrieve()`, `retrieveBy()`, and related methods is completely untyped. There is no IDE autocompletion, no validation that keys are spelled correctly, and no single place to see what options are available. A typo like `'withcount'` instead of `'with_count'` is silently ignored.

**Solution**

Introduce a strongly-typed `QueryOptions` DTO. It is backwards-compatible — all existing `array` calls continue to work because `retrieve()` is updated to accept `array|QueryOptions`.

```php
<?php

declare(strict_types=1);

namespace Frontier\Repositories\ValueObjects;

/**
 * Strongly-typed query options for repository retrieval methods.
 *
 * Provides IDE autocompletion and catches typos at construction time.
 * Pass directly to retrieve(), retrieveBy(), and pagination methods:
 *
 *   $options = new QueryOptions(
 *       sort: 'created_at',
 *       direction: 'desc',
 *       with: ['profile'],
 *       limit: 20,
 *   );
 *   $users = $userRepo->retrieve(['*'], $options);
 */
final class QueryOptions
{
    /**
     * @param  array<string, mixed>  $filters     EloquentFilter filters (requires Filterable trait on model)
     * @param  array<int|string, mixed>  $scopes  Local scopes: ['active'] or ['status' => ['active']]
     * @param  array<int|string, mixed>  $joins   Join scopes: ['withTeam'] or ['joinTeam' => [$teamId]]
     * @param  string|array<int, string>|null  $sort       Column(s) to sort by; prefix with 'raw:' for raw SQL
     * @param  string|array<int, string>|null  $direction  'asc'/'desc' or array matching $sort
     * @param  string|array<int, string>|null  $groupBy    Column(s) to group by
     * @param  array<int|string, mixed>  $with       Eager-load relations
     * @param  array<int, string>  $withCount          Count relations
     * @param  bool  $distinct                           Apply DISTINCT
     * @param  int|null  $limit                          Limit rows (retrieve() only, not pagination)
     * @param  int|null  $offset                         Offset rows (retrieve() only, not pagination)
     */
    public function __construct(
        public readonly array $filters = [],
        public readonly array $scopes = [],
        public readonly array $joins = [],
        public readonly string|array|null $sort = null,
        public readonly string|array|null $direction = null,
        public readonly string|array|null $groupBy = null,
        public readonly array $with = [],
        public readonly array $withCount = [],
        public readonly bool $distinct = false,
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
    ) {}

    /**
     * Convert to the legacy array format accepted by Retrievable::applyQueryOptions().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'filters'    => $this->filters ?: null,
            'scopes'     => $this->scopes ?: null,
            'joins'      => $this->joins ?: null,
            'sort'       => $this->sort,
            'direction'  => $this->direction,
            'group_by'   => $this->groupBy,
            'with'       => $this->with ?: null,
            'with_count' => $this->withCount ?: null,
            'distinct'   => $this->distinct ?: null,
            'limit'      => $this->limit,
            'offset'     => $this->offset,
        ], fn ($v) => $v !== null);
    }
}
```

**Integration** — update `BaseRepository::retrieve()` signature (backwards-compatible):

```php
use Frontier\Repositories\ValueObjects\QueryOptions;

public function retrieve(array $columns = ['*'], array|QueryOptions $options = []): Collection
{
    $opts = $options instanceof QueryOptions ? $options->toArray() : $options;
    return $this->getRetrieveQuery($columns, $opts)->get();
}
```

Apply the same `array|QueryOptions` union type to all methods accepting `$options`.

---

### A2 · Per-Call Cache TTL via `cacheFor()`

**Priority**: Medium
**File**: `src/BaseRepositoryCache.php`

**Problem**
All read operations share the same `$ttl` set in the constructor (defaulting to the global config). There is no way to specify a shorter or longer TTL for a single call — e.g., `count()` may be stale-tolerant for 1 hour, while `find()` for the current user's profile might need a 30-second TTL.

**Solution**

Add a fluent `cacheFor()` method that overrides the TTL for the next call only:

```php
/** @var int|null Overrides $ttl for the next cached() call only */
protected ?int $onceTtl = null;

/**
 * Override the cache TTL for the next read operation only.
 *
 * Resets automatically after the next cached() call.
 *
 *   $repo->cacheFor(30)->findById($id);   // cached for 30 seconds
 *   $repo->findById($id);                  // back to default TTL
 */
public function cacheFor(int $seconds): static
{
    $this->onceTtl = $seconds;

    return $this;
}
```

Update `cached()` to consume `$onceTtl`:

```php
protected function cached(string $method, array $params, callable $callback): mixed
{
    ...
    $ttl          = $this->onceTtl ?? $this->ttl;
    $this->onceTtl = null; // consume immediately

    return $store->remember($key, $ttl, $callback);
}
```

Also add `cacheFor()` to the `RepositoryCache` contract:

```php
// src/Contracts/RepositoryCache.php
public function cacheFor(int $seconds): static;
```

---

### A3 · Soft-Delete Restore Methods

**Priority**: Medium
**Files**: `src/BaseRepository.php`, `src/Contracts/Concerns/Deletable.php`

**Problem**
The package provides no way to restore soft-deleted records through the repository API. Developers must break the abstraction and call `withTrashed()->restore()` directly on the model/builder.

**Solution**

Add restore methods. They only work when the underlying model uses the `SoftDeletes` trait — document this requirement.

```php
// src/Contracts/Concerns/Deletable.php

/**
 * Restore soft-deleted records matching conditions.
 *
 * NOTE: Requires the model to use the SoftDeletes trait.
 * Throws a BadMethodCallException if the model does not support soft deletes.
 *
 * @param  array<string, mixed>  $conditions
 * @return int  Number of restored rows
 */
public function restore(array $conditions): int;

/**
 * Restore a single soft-deleted record by its primary key.
 *
 * @param  int|string  $id
 * @return bool  True if restored, false if not found in trash
 */
public function restoreById(int|string $id): bool;
```

```php
// src/BaseRepository.php

public function restore(array $conditions): int
{
    return $this->newQuery()
        ->withTrashed()
        ->where($conditions)
        ->restore();
}

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
```

**Cache decorator**:

```php
public function restore(array $conditions): int
{
    return tap($this->repository->restore($conditions), fn (): bool => $this->clearCache());
}

public function restoreById(int|string $id): bool
{
    return tap($this->repository->restoreById($id), fn (): bool => $this->clearCache());
}
```

---

### A4 · OR Conditions Support

**Priority**: Medium
**Files**: `src/BaseRepository.php`, `src/Contracts/Concerns/Readable.php`

**Problem**
All `find()` and `retrieveBy()` variants accept `array $conditions` which Eloquent turns into `WHERE a = 1 AND b = 2`. There is no API for `WHERE a = 1 OR b = 2` without dropping to raw builder via `withBuilder()` or `getBuilder()`.

**Solution**

Add `findByOr()` and `retrieveByOr()` that accept an array of condition groups — each group is an AND block, groups are OR-chained:

```php
// src/Contracts/Concerns/Readable.php

/**
 * Find a single record matching any of the provided condition groups.
 *
 * Each condition group is AND-chained internally; groups are OR-chained together:
 *   findByOr([['status' => 'active'], ['role' => 'admin']])
 *   → WHERE (status = 'active') OR (role = 'admin')
 *
 * @param  array<int, array<string, mixed>>  $conditionGroups
 * @param  array<int, string>  $columns
 */
public function findByOr(array $conditionGroups, array $columns = ['*']): ?Model;

/**
 * Retrieve all records matching any of the provided condition groups.
 *
 * @param  array<int, array<string, mixed>>  $conditionGroups
 * @param  array<int, string>  $columns
 * @param  array<string, mixed>  $options
 * @return Collection<int, Model>
 */
public function retrieveByOr(array $conditionGroups, array $columns = ['*'], array $options = []): Collection;
```

```php
// src/BaseRepository.php

public function findByOr(array $conditionGroups, array $columns = ['*']): ?Model
{
    $query = $this->newQuery()->select($this->prefixColumns($columns));

    foreach ($conditionGroups as $index => $conditions) {
        $index === 0 ? $query->where($conditions) : $query->orWhere($conditions);
    }

    return $query->first();
}

public function retrieveByOr(array $conditionGroups, array $columns = ['*'], array $options = []): Collection
{
    $query = $this->getRetrieveQuery($columns, $options);

    foreach ($conditionGroups as $index => $conditions) {
        $index === 0 ? $query->where($conditions) : $query->orWhere($conditions);
    }

    return $query->get();
}
```

---

### A5 · Repository Lifecycle Hooks (Advanced)

**Priority**: Low
**Files**: `src/BaseRepository.php` (new trait or extension)

**Problem**
There is no way to hook into repository operations without subclassing and overriding each method. This makes cross-cutting concerns (audit logging, domain event dispatch, metrics) cumbersome to implement.

**Solution**

Introduce a `WithHooks` trait that can be optionally applied to concrete repositories:

```php
<?php

declare(strict_types=1);

namespace Frontier\Repositories\Traits;

/**
 * Adds lifecycle hook support to a repository.
 *
 * Apply this trait to a concrete repository to intercept operations:
 *
 *   class UserRepository extends BaseRepository
 *   {
 *       use WithHooks;
 *
 *       public function __construct()
 *       {
 *           parent::__construct(new User);
 *           $this->afterCreate(fn ($model) => UserCreated::dispatch($model));
 *       }
 *   }
 */
trait WithHooks
{
    /** @var array<string, array<int, callable>> */
    protected array $hooks = [];

    public function beforeCreate(callable $hook): static
    {
        $this->hooks['beforeCreate'][] = $hook;
        return $this;
    }

    public function afterCreate(callable $hook): static
    {
        $this->hooks['afterCreate'][] = $hook;
        return $this;
    }

    public function afterUpdate(callable $hook): static
    {
        $this->hooks['afterUpdate'][] = $hook;
        return $this;
    }

    public function afterDelete(callable $hook): static
    {
        $this->hooks['afterDelete'][] = $hook;
        return $this;
    }

    protected function fireHook(string $event, mixed ...$args): void
    {
        foreach ($this->hooks[$event] ?? [] as $hook) {
            $hook(...$args);
        }
    }
}
```

Usage in `create()` override:

```php
public function create(array $values): Model
{
    $this->fireHook('beforeCreate', $values);
    $model = parent::create($values);
    $this->fireHook('afterCreate', $model);

    return $model;
}
```

---

## 4. Configuration Enhancements

### C1 · Extended Cache Configuration

**Priority**: Medium
**File**: `config/repository-cache.php`

**Problem**
The current config only exposes 4 options. Production applications often need:
- Per-model TTL (settings table: 24h; notifications: 30s)
- Control over whether empty results are cached
- Method-level exclusions (e.g., never cache `count()`)

**Solution**

```php
<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Repository Cache Enabled
    |--------------------------------------------------------------------------
    |
    | Global toggle. When false, all read operations bypass the cache and
    | write operations skip cache invalidation.
    |
    */
    'enabled' => env('REPOSITORY_CACHE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Cache Driver
    |--------------------------------------------------------------------------
    |
    | The cache store to use. Null means the application's default driver.
    | Use a tag-aware driver (Redis, Memcached) to enable clearCache().
    |
    */
    'driver' => env('REPOSITORY_CACHE_DRIVER', null),

    /*
    |--------------------------------------------------------------------------
    | Default TTL (Time To Live)
    |--------------------------------------------------------------------------
    |
    | Seconds to cache repository read results. Can be overridden:
    |   - Per-repository: new MyRepositoryCache(ttl: 300)
    |   - Per-call:       $repo->cacheFor(30)->findById($id)
    |   - Per-model:      'model_ttl' option below
    |
    */
    'ttl' => env('REPOSITORY_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | A global prefix prepended to all repository cache keys.
    | The per-repository prefix defaults to the model's table name.
    |
    */
    'prefix' => env('REPOSITORY_CACHE_PREFIX', 'repository'),

    /*
    |--------------------------------------------------------------------------
    | Cache Empty Results
    |--------------------------------------------------------------------------
    |
    | When true, null returns and empty collections are cached like any other
    | result. When false, empty results bypass the cache — useful when empty
    | results indicate a temporary state (e.g., a job has not run yet).
    |
    */
    'cache_empty_results' => env('REPOSITORY_CACHE_EMPTY_RESULTS', true),

    /*
    |--------------------------------------------------------------------------
    | Excluded Methods
    |--------------------------------------------------------------------------
    |
    | Method names listed here are never cached, even if caching is globally
    | enabled. Useful for volatile aggregates like real-time counts.
    |
    | Example: ['count', 'exists']
    |
    */
    'excluded_methods' => [],

    /*
    |--------------------------------------------------------------------------
    | Per-Model TTL Overrides
    |--------------------------------------------------------------------------
    |
    | Override the default TTL for specific models, keyed by table name.
    | The BaseRepositoryCache reads this in getCacheTtl() when no explicit
    | TTL is passed to the constructor.
    |
    | Example:
    |   'model_ttl' => [
    |       'settings'      => 86400,   // 24 hours — rarely changes
    |       'notifications' => 60,      // 1 minute — high churn
    |   ],
    |
    */
    'model_ttl' => [],

];
```

**Integration** — `BaseRepositoryCache::getCacheTtl()` should respect `model_ttl`:

```php
public function getCacheTtl(): int
{
    $modelTtls = config('repository-cache.model_ttl', []);
    $table     = $this->repository->getTable();

    return $modelTtls[$table] ?? $this->ttl;
}
```

---

## 5. Developer Experience

### DX1 · Improve Code Generation Stubs

**Priority**: Medium
**Files**: `stubs/*.stub`

**Problem**
Current stubs are bare-bones (2–6 lines). They do not show model injection, provide no docblocks, and give no guidance on common patterns.

**Solution**

**`stubs/repository.stub`**:

```php
<?php

declare(strict_types=1);

namespace $NAMESPACE$;

use Frontier\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent repository for the $CLASS_NAME$ model.
 *
 * Add custom query methods below. All built-in CRUD operations are
 * inherited from BaseRepository. Use the $options array (or QueryOptions DTO)
 * to add filters, scopes, sorting, eager loading, and pagination.
 *
 * Example:
 *   public function findActive(array $columns = ['*']): Collection
 *   {
 *       return $this->retrieve($columns, ['scopes' => ['active']]);
 *   }
 */
class $CLASS_NAME$ extends BaseRepository
{
    public function __construct()
    {
        // Replace with the model this repository manages:
        parent::__construct(new Model);
    }
}
```

**`stubs/repository-cache.stub`**:

```php
<?php

declare(strict_types=1);

namespace $NAMESPACE$;

use Frontier\Repositories\BaseRepositoryCache;

/**
 * Caching decorator for $CLASS_NAME$.
 *
 * Wraps the corresponding Eloquent repository and transparently caches
 * all read operations. Write operations invalidate the cache automatically.
 *
 * Bind in your AppServiceProvider:
 *   $this->app->bind(UserRepositoryInterface::class, fn ($app) =>
 *       new UserRepositoryCache(
 *           new UserRepositoryEloquent(new User),
 *           ttl: 3600,
 *       )
 *   );
 */
class $CLASS_NAME$ extends BaseRepositoryCache {}
```

**`stubs/repository-interface.stub`**:

```php
<?php

declare(strict_types=1);

namespace $NAMESPACE$;

use Frontier\Repositories\Contracts\Repository;

/**
 * Repository contract for $CLASS_NAME$.
 *
 * Extend with model-specific methods:
 *   public function findByEmail(string $email): ?Model;
 *
 * Type-hint against the narrowest sub-interface when possible:
 *   use Frontier\Repositories\Contracts\Concerns\Readable;
 *   public function __construct(Readable $users) {}
 */
interface $CLASS_NAME$ extends Repository {}
```

**`stubs/repository-action.stub`**:

```php
<?php

declare(strict_types=1);

namespace $NAMESPACE$;

use Frontier\Repositories\BaseAction;

/**
 * Action backed by a repository.
 *
 * Inject a concrete repository in the constructor and implement handle():
 *
 *   public function __construct(UserRepository $users)
 *   {
 *       $this->repository = $users;
 *   }
 *
 *   public function handle(int $id): User
 *   {
 *       return $this->repository()->findByIdOrFail($id);
 *   }
 */
class $CLASS_NAME$ extends BaseAction
{
    public function handle(): mixed
    {
        // TODO: implement
    }
}
```

---

### DX2 · Generator Command Improvements

**Priority**: Low
**Files**: `src/Console/Commands/*.php`

**Proposed additions** (one or more):

1. **`--force` flag** — overwrite an existing file without aborting
2. **`--test` flag** — generate a corresponding Pest test file alongside the class
3. **Class name validation** — reject names that contain invalid characters or are reserved PHP keywords
4. **Interactive model prompt** in `MakeRepository` — offer to enter the model class name so the constructor is pre-filled

Example validation:

```php
protected function validateClassName(string $name): void
{
    if (! preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
        throw new \InvalidArgumentException(
            "Class name [{$name}] must start with an uppercase letter and contain only alphanumeric characters."
        );
    }
}
```

---

### DX3 · Container Binding Pattern in Documentation

**Priority**: Low
**File**: `CLAUDE.md` or `README.md`

**Problem**
The ServiceProvider registers no default bindings. Developers must figure out how to wire repositories to their interfaces. There is no documentation showing the pattern.

**Solution**

Add a "Binding to the Container" section to the README:

```php
// In app/Providers/AppServiceProvider.php
use App\Models\User;
use App\Repositories\Users\UserRepository;
use App\Repositories\Users\UserRepositoryCache;
use App\Repositories\Users\UserRepositoryInterface;

public function register(): void
{
    $this->app->bind(UserRepositoryInterface::class, function ($app) {
        return new UserRepositoryCache(
            repository: new UserRepository(new User),
            ttl: 3600,
        );
    });
}
```

---

## 6. Test Coverage

The current test suite has approximately **20% coverage** of the package's functionality. The following table summarises the gap:

| Component | Current Tests | Recommended | Gap |
|-----------|:------------:|:-----------:|:---:|
| `BaseRepository` methods | 1 | 25+ | 24 |
| `BaseRepositoryCache` behaviour | 4 | 15+ | 11 |
| `Retrievable` options | 12 | 30+ | 18 |
| Artisan commands | 3 | 15+ | 12 |
| Integration (full flow) | 0 | 10+ | 10 |
| **Total** | **~20** | **95+** | **75+** |

### T1 · `BaseRepository` Method Tests

Key scenarios to cover for each method group:

```php
describe('BaseRepository', function () {

    describe('create / createMany', function () {
        it('creates a single record and returns a Model')
        it('createMany wraps in a transaction')
        it('createMany returns all created models')
        it('insertMany performs a bulk insert in chunks')
        it('insertMany returns false when insert fails')
    });

    describe('find / findById / findByIds', function () {
        it('returns null when no record matches')
        it('returns a Model when a record matches')
        it('findOrFail throws ModelNotFoundException when not found')
        it('findByIds returns a collection of matching models')
        it('findByIds silently omits missing IDs')
    });

    describe('update / updateById', function () {
        it('update returns the number of affected rows')
        it('updateOrFail throws when no rows are updated')
        it('updateById returns null when model not found')
        it('updateById returns the updated model')
        it('updateByIdQuery performs a single-query update')
    });

    describe('delete / deleteById / deleteByIds', function () {
        it('delete returns the number of deleted rows')
        it('deleteOrFail throws when no rows are deleted')
        it('deleteById returns false when model not found')
        it('deleteById returns true and deletes the model')
        it('deleteByIds runs inside a transaction')
        it('restore restores soft-deleted records')
        it('restoreById returns false when not found in trash')
    });

    describe('aggregate', function () {
        it('count returns 0 for an empty table')
        it('count applies conditions')
        it('exists returns false for missing conditions')
        it('exists returns true when matching records exist')
    });
});
```

### T2 · `Retrievable` Options Tests

```php
describe('Retrievable options', function () {
    it('applies the scopes option')
    it('applies the joins option')
    it('applies group_by')
    it('applies distinct')
    it('applies with for eager loading')
    it('applies with_count')
    it('applies limit and offset (retrieve only)')
    it('ignores limit/offset on paginate methods')
    it('throws InvalidArgumentException for invalid scope name')
    it('throws InvalidArgumentException for invalid join name')
    it('accepts QueryOptions DTO as well as plain array')
});
```

### T3 · Cache Behaviour Tests

```php
describe('BaseRepositoryCache', function () {
    it('does not reset skipCache flag if callback throws')  // R1 regression test
    it('does not reset forceRefresh flag if callback throws')
    it('cacheFor() overrides TTL for one call only')
    it('cacheFor() resets after the call')
    it('withoutCache() bypasses the cache for one call')
    it('refreshCache() forces re-execution and re-caching')
    it('clearCache() returns false and logs warning for non-tag drivers')
    it('excluded_methods config prevents caching of listed methods')
    it('findByIds() is cached and invalidated on write')
    it('restore() invalidates the cache')
});
```

### T4 · Command Tests

```php
describe('MakeRepository command', function () {
    it('creates a repository file at the expected path')
    it('refuses to overwrite without --force')
    it('overwrites with --force')
    it('generates a test file with --test')
    it('validates class name format')
    it('creates in the correct module directory with --module')
});
```

---

## 7. Implementation Roadmap

Items are ordered by risk-adjusted priority: fix correctness bugs first, then add features, then polish.

| # | Item | Priority | Effort | Impact |
|---|------|:--------:|:------:|:------:|
| 1 | **R1** — Exception-safe cache flags | Critical | XS | High |
| 2 | **R4** — Fix DANGEROUS_SQL_PATTERN | Critical | XS | Medium |
| 3 | **P1** — `createMany()` transaction + `insertMany()` | Critical | S | High |
| 4 | **P3** — Add `findByIds()` | High | S | High |
| 5 | **R2** — Complete `BaseAction` | High | XS | High |
| 6 | **R3** — Scope name validation | High | S | Medium |
| 7 | **R5** — Remove undocumented config fallback | High | XS | Medium |
| 8 | **T1–T4** — Test coverage expansion | High | L | High |
| 9 | **A1** — `QueryOptions` DTO | Medium | M | High |
| 10 | **A2** — `cacheFor()` per-call TTL | Medium | S | Medium |
| 11 | **A3** — Soft-delete restore methods | Medium | S | Medium |
| 12 | **A4** — OR conditions support | Medium | S | Medium |
| 13 | **C1** — Extended cache config | Medium | S | Medium |
| 14 | **DX1** — Improved stubs | Medium | S | Medium |
| 15 | **P2** — Document `updateById()` trade-off + `updateByIdQuery()` | Medium | XS | Medium |
| 16 | **DX2** — Generator `--force` / `--test` flags | Low | M | Low |
| 17 | **A5** — `WithHooks` trait | Low | L | Low |
| 18 | **DX3** — Container binding docs | Low | XS | Low |

**Effort key**: XS < 1 h · S 1–2 h · M 2–4 h · L 4–8 h

---

## Verification Checklist

After implementing all changes, verify with:

```bash
# All tests pass
composer test

# Zero style violations
composer lint:test

# Zero Rector suggestions
composer rector:dry

# Coverage target ≥ 80 %
vendor/bin/pest --coverage --min=80

# Manual smoke tests
php artisan frontier:repository User
php artisan frontier:repository-cache UserCache
php artisan frontier:repository-interface UserRepositoryInterface
php artisan frontier:repository-action CreateUserAction
```
