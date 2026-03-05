# CLAUDE.md — Frontier Repository

This file provides guidance for AI assistants (Claude and others) working in this codebase.

---

## Project Overview

**frontier/repository** is a Laravel package implementing the Repository Pattern with optional transparent caching via the Decorator Pattern. It provides a clean, consistent API for Eloquent-based data access.

- **Package**: `frontier/repository`
- **Namespace**: `Frontier\Repositories`
- **PHP**: >= 8.2
- **Laravel**: 10, 11, 12
- **License**: MIT

---

## Repository Structure

```
frontier-repository/
├── src/
│   ├── BaseRepository.php          # Core Eloquent repository implementation
│   ├── BaseRepositoryCache.php     # Caching decorator wrapping BaseRepository
│   ├── BaseAction.php              # Base action class integrating with frontier/action
│   ├── Contracts/
│   │   ├── Repository.php          # Main interface (composes all Concerns)
│   │   ├── RepositoryCache.php     # Cache control interface
│   │   └── Concerns/               # ISP-split sub-interfaces
│   │       ├── Creatable.php       # CREATE operations
│   │       ├── Readable.php        # READ + pagination + aggregation
│   │       ├── Updatable.php       # UPDATE operations
│   │       ├── Deletable.php       # DELETE operations
│   │       └── RepositoryUtility.php # chunk, transaction, builder access
│   ├── Traits/
│   │   └── Retrievable.php         # Query building logic (used by BaseRepository)
│   └── Providers/
│       └── ServiceProvider.php     # Registers commands and publishes config
├── config/
│   └── repository-cache.php        # Cache configuration (enabled, driver, ttl, prefix)
├── stubs/
│   ├── repository.stub             # Stub for concrete repository
│   ├── repository-cache.stub       # Stub for cache decorator
│   ├── repository-interface.stub   # Stub for user repository interface
│   └── repository-action.stub      # Stub for repository-bound action
├── tests/
│   ├── Pest.php                    # Pest bootstrap (extends TestCase)
│   ├── TestCase.php                # Orchestra Testbench base
│   ├── Unit/                       # Unit tests
│   └── Feature/                    # Feature tests (Artisan commands)
├── composer.json
├── phpunit.xml
└── rector.php
```

---

## Architecture

### Core Pattern

```
UserRepository (interface)
       │
       └─── bound to either:
            ├── UserRepositoryEloquent   extends BaseRepository       (direct DB)
            └── UserRepositoryCache      extends BaseRepositoryCache   (decorator)
                    └── wraps UserRepositoryEloquent
```

### BaseRepository (`src/BaseRepository.php`)

- Abstract class implementing `Repository` contract via `Retrievable` trait
- Takes an Eloquent `Model` in the constructor
- Every method creates a **fresh query builder** (`newQuery()`) — no state leaks between calls
- Optional `withBuilder(Builder $builder)` sets a base builder cloned for all queries
- Methods follow naming conventions: `find*` (single record), `retrieve*` (collections/pagination), `update*`, `delete*`

### BaseRepositoryCache (`src/BaseRepositoryCache.php`)

- Decorator wrapping any `Repository` contract implementation
- Read methods (`retrieve*`, `find*`, `count`, `exists`) use `cached()` helper — returns from cache or executes and stores
- Write methods (`create*`, `update*`, `delete*`, `insert*`, `upsert`) call the inner repo then call `clearCache()`
- Cache key: `{prefix}:{method}:md5(serialized params)` — keys are stable (closures replaced with file+line fingerprints)
- Tag support: if the driver supports tags, uses tagged cache for efficient `clearCache()`

### Retrievable Trait (`src/Traits/Retrievable.php`)

Handles the `$options` array in `retrieve()` / `retrievePaginate()`:

| Option | Behavior |
|--------|----------|
| `filters` | Calls `->filter($filters)` (requires Filterable trait on model) |
| `scopes` | Applies local scopes; keyed = scope with args, numeric = no-arg scope |
| `joins` | Applies join scopes similarly to scopes |
| `group_by` | `->groupBy(...)` |
| `distinct` | `->distinct()` |
| `sort` | Column name, array of names, or `'raw:SQL_EXPR'` prefix for raw |
| `direction` | `'asc'`/`'desc'` or array matching `sort` |
| `with` | Eager load relations |
| `with_count` | Count relations |
| `limit` | Only for `retrieve()`, not pagination methods |
| `offset` | Only for `retrieve()`, not pagination methods |

Column prefixing: all columns are automatically prefixed with the table name (e.g. `users.id`) unless they already contain `.` or are prefixed with `@`.

SQL injection safeguards:
- Direction values are whitelisted to `asc`/`desc`
- Column names are validated with regex `/^[a-zA-Z0-9_\.\*]+(\s+as\s+\w+)?$/`
- Raw expressions are rejected if they contain `DELETE`, `UPDATE`, `INSERT`, `DROP`, `ALTER`

---

## Interface Segregation

The `Repository` contract is composed of focused sub-interfaces in `src/Contracts/Concerns/`. Type-hint against the narrowest interface you need:

```php
use Frontier\Repositories\Contracts\Concerns\Readable;
use Frontier\Repositories\Contracts\Concerns\Creatable;

// Read-only service
public function __construct(Readable $repository) {}

// Intersection type for read+update
public function handle(Readable&Updatable $repository) {}
```

---

## Development Commands

```bash
composer test          # Run all tests (Pest)
composer test:coverage # Run tests with coverage
composer lint          # Fix code style (Laravel Pint)
composer lint:test     # Check style without fixing
composer rector        # Apply Rector refactorings
composer rector:dry    # Preview Rector changes
```

---

## Testing

- **Framework**: Pest 3 with `pestphp/pest-plugin-laravel`
- **Test base**: `Orchestra\Testbench` — no full Laravel app needed
- **Structure**: `tests/Unit/` for isolated unit tests, `tests/Feature/` for integration/command tests
- **Mocking**: Mockery is used (provided by Orchestra Testbench)
- Tests use `describe()` + `it()` Pest syntax with `declare(strict_types=1)`

Run a specific suite:
```bash
vendor/bin/pest --testsuite Unit
vendor/bin/pest --testsuite Feature
```

---

## Code Style and Conventions

1. **Strict types**: Every PHP file must start with `declare(strict_types=1);`
2. **PSR-12** coding standard enforced by **Laravel Pint** — run `composer lint` before committing
3. **Rector** is configured for PHP 8.2 with `CODE_QUALITY`, `DEAD_CODE`, `EARLY_RETURN`, and `TYPE_DECLARATION` sets — run `composer rector` to apply
4. **PHPDoc**: All public methods have `@param` and `@return` type annotations; arrays specify key/value types (e.g. `array<string, mixed>`)
5. **Method naming**:
   - `find*` — single model or null
   - `findOrFail*` / `*OrFail` — throws `ModelNotFoundException` when not found
   - `retrieve*` — collections or paginators
   - `update*` / `delete*` using `update($conditions, $values)` — bulk query-level (no model events)
   - `updateBy*` / `deleteBy*` — model-level (triggers events/casts)
   - `*ById` variants accept a scalar primary key
6. **No state mutation** between queries in `BaseRepository` — always call `newQuery()`

---

## Artisan Commands

Registered by `ServiceProvider`. Commands live in `src/Console/Commands/`:

| Command | Class | Description |
|---------|-------|-------------|
| `frontier:repository {Name}` | `MakeRepository` | Create a concrete repository |
| `frontier:repository-cache {Name}` | `MakeRepositoryCache` | Create a cache decorator |
| `frontier:repository-interface {Name}` | `MakeRepositoryInterface` | Create a repository interface |
| `frontier:repository-action {Name}` | `MakeRepositoryAction` | Create a repository-bound action |

All commands support `--module` (requires `frontier/module`) for modular app layouts.

---

## Configuration

Config file: `config/repository-cache.php`

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `enabled` | `REPOSITORY_CACHE_ENABLED` | `true` | Global toggle for caching |
| `driver` | `REPOSITORY_CACHE_DRIVER` | `null` (default driver) | Cache store name |
| `ttl` | `REPOSITORY_CACHE_TTL` | `3600` | Seconds to cache |
| `prefix` | `REPOSITORY_CACHE_PREFIX` | `'repository'` | Cache key prefix |

Publish: `php artisan vendor:publish --tag=repository-config`

---

## Cache Invalidation

- Write operations use `tap()` + `clearCache()` — the result is returned transparently
- `clearCache()` flushes tagged cache entries when the driver supports tags (Redis, Memcached)
- For drivers without tag support (file, array), manual key-based invalidation is not implemented; disable/enable caching via `REPOSITORY_CACHE_ENABLED`
- `withoutCache()` and `refreshCache()` are per-request fluent flags that reset after each call

---

## Key Files for AI Reference

| Task | File |
|------|------|
| Add a repository method | `src/Contracts/Concerns/*.php` (contract) + `src/BaseRepository.php` (impl) + `src/BaseRepositoryCache.php` (cache wrapper) |
| Modify query option handling | `src/Traits/Retrievable.php` |
| Register a new Artisan command | `src/Providers/ServiceProvider.php` + new file in `src/Console/Commands/` |
| Change cache behavior | `src/BaseRepositoryCache.php` |
| Add a new config option | `config/repository-cache.php` |
| Write tests | `tests/Unit/` or `tests/Feature/` |

---

## External Dependencies

| Package | Role |
|---------|------|
| `frontier/action` | `BaseAction` extends `FrontierBaseAction` |
| `tucker-eric/eloquentfilter` | Optional — enables `filters` option in `retrieve()` (model needs `Filterable` trait) |
| `frontier/module` | Optional — enables `--module` flag on generator commands |
