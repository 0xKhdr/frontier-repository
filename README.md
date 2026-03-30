<h1 align="center">Frontier Repository</h1>

<p align="center">
  Repository Pattern + Optional Transparent Caching for Laravel
</p>

<p align="center">
  <img src="https://img.shields.io/packagist/v/frontier/repository" alt="Latest Version">
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4" alt="PHP Version">
  <img src="https://img.shields.io/badge/Laravel-10|11|12-FF2D20" alt="Laravel Version">
</p>

---

## Table of Contents

- [Why this package?](#why-this-package)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Core Usage](#core-usage)
- [Query Options](#query-options)
- [Caching](#caching)
- [Configuration](#configuration)
- [Artisan Generators](#artisan-generators)
- [V2 Notes (Breaking Changes)](#v2-notes-breaking-changes)
- [Development](#development)
- [Contributing](#contributing)
- [License](#license)

---

## Why this package?

`frontier/repository` gives you a clean repository contract over Eloquent, with optional caching via a decorator.

### What you get

- ✅ Consistent CRUD API (`create`, `get`, `paginate`, `update`, `delete`, etc.)
- ✅ Interface-first architecture (easy mocking, testing, swapping implementations)
- ✅ Optional cache decorator (`BaseRepositoryCache`) for read methods
- ✅ Cache invalidation on writes out-of-the-box
- ✅ Advanced query options (filters, scopes, eager loads, sorting, grouping)
- ✅ Code generators for repository/interface/cache scaffolding
- ✅ Optional modular support via `internachi/modular` (`--module` option)

---

## Requirements

- PHP **8.2+**
- Laravel **10 / 11 / 12**

---

## Installation

```bash
composer require frontier/repository
```

If you want to customize cache config:

```bash
php artisan vendor:publish --tag=repository-config
```

---

## Quick Start

### 1) Generate an interface

```bash
php artisan frontier:repository-interface UserRepository
```

### 2) Generate an Eloquent repository

```bash
php artisan frontier:repository UserRepositoryEloquent
```

### 3) (Optional) Generate a cache decorator

```bash
php artisan frontier:repository-cache UserRepositoryCache
```

### 4) Set your model in the generated repository

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use Frontier\Repositories\BaseRepository;

class UserRepositoryEloquent extends BaseRepository implements UserRepository
{
    public function __construct()
    {
        parent::__construct(new User());
    }
}
```

### 5) Bind interface to implementation

```php
// app/Providers/AppServiceProvider.php

use App\Models\User;
use App\Repositories\UserRepository;
use App\Repositories\UserRepositoryCache;
use App\Repositories\UserRepositoryEloquent;

public function register(): void
{
    // Option A: No caching
    $this->app->bind(UserRepository::class, fn () => new UserRepositoryEloquent());

    // Option B: Cached decorator
    // $this->app->bind(UserRepository::class, fn () =>
    //     new UserRepositoryCache(new UserRepositoryEloquent())
    // );
}
```

---

## Core Usage

Inject your interface and use a consistent API.

```php
use App\Repositories\UserRepository;

class UserController
{
    public function __construct(private UserRepository $users) {}

    public function index()
    {
        return $this->users->get(['id', 'name', 'email'], [
            'sort' => 'created_at',
            'direction' => 'desc',
            'limit' => 20,
        ]);
    }
}
```

### CRUD examples

```php
// CREATE
$user = $users->create(['name' => 'John', 'email' => 'john@example.com']);
$users->createMany([
    ['name' => 'A', 'email' => 'a@example.com'],
    ['name' => 'B', 'email' => 'b@example.com'],
]);

// READ
$one = $users->find(1);
$oneOrFail = $users->findOrFail(1);
$by = $users->findBy(['email' => 'john@example.com']);
$list = $users->get();

// PAGINATION
$page = $users->paginate(perPage: 15);
$simple = $users->simplePaginate(perPage: 15);
$cursor = $users->cursorPaginate(perPage: 50);

// UPDATE
$affected = $users->update(['status' => 'pending'], ['status' => 'processed']);
$updatedModel = $users->updateById(1, ['name' => 'Johnny']);
$users->upsert([
    ['email' => 'john@example.com', 'name' => 'John'],
    ['email' => 'jane@example.com', 'name' => 'Jane'],
], ['email'], ['name']);

// DELETE
$deleted = $users->delete(['status' => 'inactive']);
$users->deleteById(1);
$users->deleteMany([2, 3, 4]);

// AGGREGATION
$total = $users->count();
$exists = $users->exists(['email' => 'john@example.com']);
```

---

## Query Options

`get()` and pagination methods accept an `$options` array.

```php
$users = $usersRepository->get(['id', 'name', 'email'], [
    'filters' => ['status' => 'active'],     // requires model Filterable trait
    'scopes' => ['verified', 'olderThan' => [18]],
    'joins' => ['profile'],
    'with' => ['profile', 'roles'],
    'with_count' => ['posts'],
    'sort' => ['name', 'created_at'],
    'direction' => ['asc', 'desc'],
    'group_by' => ['status'],
    'distinct' => true,
    'limit' => 25,    // get() only
    'offset' => 0,    // get() only
]);
```

### Options reference

| Option | Type | Notes |
|---|---|---|
| `filters` | array | Uses `->filter()` (EloquentFilter package/model trait required) |
| `scopes` | array | Applies local scopes |
| `joins` | array | Applies join scopes |
| `with` | array | Eager load relations |
| `with_count` | array | Relation counts |
| `sort` | string\|array | Supports `raw:` prefix for raw expressions |
| `direction` | string\|array | `asc` / `desc` |
| `group_by` | string\|array | Grouping columns |
| `distinct` | bool | Applies `distinct()` |
| `limit` | int | `get()` only |
| `offset` | int | `get()` only |

### Using `QueryOptions` value object

```php
use Frontier\Repositories\ValueObjects\QueryOptions;

$options = new QueryOptions(
    sort: 'created_at',
    direction: 'desc',
    with: ['profile'],
    limit: 20,
);

$users = $usersRepository->get(['*'], $options);
```

---

## Caching

Caching uses the **Decorator Pattern**:

- `BaseRepository` → direct database operations
- `BaseRepositoryCache` → wraps a repository and caches read operations

### Read/Write behavior

| Category | Methods |
|---|---|
| Cached reads | `get*`, `paginate*`, `find*`, `count`, `exists` |
| Cache invalidating writes | `create*`, `update*`, `delete*`, `insert*`, `upsert`, `restore*` |

### Cache control

```php
$users->withoutCache()->get();     // skip cache for next read
$users->refreshCache()->get();     // force refresh for next read
$users->clearCache();              // clear tagged cache entries
```

If your concrete repository extends `BaseRepositoryCache`, you can also do:

```php
$users->cacheFor(30)->find(1);     // override TTL for one call
```

> `clearCache()` is most effective with tag-aware drivers (Redis/Memcached).

---

## Configuration

Published file: `config/repository-cache.php`

Common keys:

- `enabled` (`REPOSITORY_CACHE_ENABLED`) — global cache toggle
- `driver` (`REPOSITORY_CACHE_DRIVER`) — cache store name or default
- `ttl` (`REPOSITORY_CACHE_TTL`) — default seconds
- `prefix` (`REPOSITORY_CACHE_PREFIX`) — global key prefix setting

Example:

```php
return [
    'enabled' => env('REPOSITORY_CACHE_ENABLED', true),
    'driver' => env('REPOSITORY_CACHE_DRIVER', null),
    'ttl' => env('REPOSITORY_CACHE_TTL', 3600),
    'prefix' => env('REPOSITORY_CACHE_PREFIX', 'repository'),
];
```

---

## Artisan Generators

| Command | Description |
|---|---|
| `php artisan frontier:repository-interface {Name}` | Generate repository interface |
| `php artisan frontier:repository {Name}` | Generate concrete Eloquent repository |
| `php artisan frontier:repository-cache {Name}` | Generate cache decorator |

All commands support optional `--module` when using `internachi/modular`.

---

## V2 Notes (Breaking Changes)

If you upgraded from old API naming:

- `retrieve()` → `get()`
- `retrievePaginate()` → `paginate()`
- `deleteByIds()` → `deleteMany()`

If your app still uses old names, update method calls accordingly.

---

## Development

```bash
composer test          # Run tests
composer test:coverage # Run tests with coverage
composer lint          # Fix coding style
composer lint:test     # Check coding style only
composer rector        # Apply Rector rules
composer rector:dry    # Preview Rector changes
```

---

## Contributing

1. Follow PSR-12 (Laravel Pint)
2. Use strict types in all PHP files
3. Add/adjust Pest tests for behavior changes
4. Keep docs and stubs aligned with public API

---

## License

MIT — see [LICENSE](LICENSE).

---

## Author

**Mohamed Khedr** — [0xkhdr@gmail.com](mailto:0xkhdr@gmail.com)
