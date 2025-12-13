<p align="center">
  <h1 align="center">Frontier Repository</h1>
  <p align="center">
    <strong>Repository Pattern with Transparent Caching for Laravel</strong>
  </p>
</p>

<p align="center">
  <a href="#installation">Installation</a> •
  <a href="#quick-start">Quick Start</a> •
  <a href="#caching">Caching</a> •
  <a href="#api-reference">API Reference</a> •
  <a href="#commands">Commands</a>
</p>

<p align="center">
  <img src="https://img.shields.io/packagist/v/frontier/repository" alt="Latest Version">
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4" alt="PHP Version">
  <img src="https://img.shields.io/badge/Laravel-10|11|12-FF2D20" alt="Laravel Version">
</p>

---

## Features

- ✅ **Repository Pattern** — Clean abstraction for data access
- ✅ **Decorator Caching** — Choose between simple or cached repository implementations
- ✅ **Full CRUD** — Create, Read, Update, Delete with consistent API
- ✅ **Advanced Queries** — Filtering, sorting, pagination, scopes
- ✅ **Module Support** — Works with internachi/modular

---

## Installation

```bash
composer require frontier/repository
```

---

## Quick Start

### 1. Create Interface
It is best practice to always code against interfaces.

```bash
php artisan frontier:repository-interface UserRepository
```

### 2. Generate Repository
Create a standard repository that implements the interface.

```bash
php artisan frontier:repository UserRepositoryEloquent
```

### 3. Generate Repository Cache (Optional)
Create a decorator repository that adds caching.

```bash
php artisan frontier:repository-cache UserRepositoryCache
```

### 4. Bind in ServiceProvider
Bind your interface to either the standard repository or the cached one.

```php
// app/Providers/RepositoryServiceProvider.php

// Option A: Standard Repository (No Caching)
$this->app->bind(UserRepository::class, function ($app) {
    return new UserRepositoryEloquent(new User());
});

// Option B: Cached Repository (Repository + Caching Decorator)
$this->app->bind(UserRepository::class, function ($app) {
    return new UserRepositoryCache(
        new UserRepositoryEloquent(new User())
    );
});
```

---

## Caching

Caching is implemented via the Decorator Pattern. The `RepositoryCache` wraps your `BaseRepository` and handles caching logic transparently.

### Architecture

```
┌─────────────────────────────────────────┐
│         UserRepository         │
└───────────────────┬─────────────────────┘
                    │ bind to either:
    ┌───────────────┴───────────────┐
    ▼                               ▼
UserRepositoryEloquent              UserRepositoryCache
extends BaseRepository              extends RepositoryCache
(Direct DB Access)                  (Caching Decorator)
```

### Usage

Inject the interface into your controllers or actions:

```php
class UserController extends Controller
{
    public function __construct(
        protected UserRepository $users
    ) {}

    public function index()
    {
        // Automatically cached if UserRepositoryCache is bound
        return $this->users->retrieve();
    }
}
```

### Cache Control Methods

The `RepositoryCache` exposes helper methods to control cache behavior:

```php
// Skip cache for this query
$users->withoutCache()->retrieve();

// Force refresh cache
$users->refreshCache()->retrieve();

// Clear all cache
$users->clearCache();
```

---

## Caching Behavior

| Method | Behavior |
|--------|----------|
| `retrieve()` | Cached (Read) |
| `retrievePaginate()` | Cached (Read) |
| `find()` | Cached (Read) |
| `findOrFail()` | Cached (Read) |
| `count()` | Cached (Read) |
| `exists()` | Cached (Read) |
| `create()` | Invalidates Cache |
| `update()` | Invalidates Cache |
| `delete()` | Invalidates Cache |
| `updateOrCreate()` | Invalidates Cache |
| `insert()` | Invalidates Cache |
| `upsert()` | Invalidates Cache |

---

## Configuration

Publish config:
```bash
php artisan vendor:publish --tag=repository-config
```

```php
// config/repository-cache.php
return [
    'enabled' => env('REPOSITORY_CACHE_ENABLED', true),
    'driver' => env('REPOSITORY_CACHE_DRIVER', null),
    'ttl' => env('REPOSITORY_CACHE_TTL', 3600),
];
```

---

## Artisan Commands

| Command | Description |
|---------|-------------|
| `frontier:repository {name}` | Create standard repository |
| `frontier:repository-cache {name}` | Create cached repository decorator |
| `frontier:repository-interface {name}` | Create repository interface |
| `frontier:repository-action {name}` | Create repository action |

All commands support the `--module` flag for modular applications.

---

## CRUD Operations

```php
// CREATE
$user = $this->users->create(['name' => 'John']);

// READ
$user = $this->users->find(['id' => 1]);
$users = $this->users->retrieve();
$users = $this->users->retrievePaginate(['*'], ['per_page' => 15]);

// UPDATE
$count = $this->users->update(['id' => 1], ['name' => 'Jane']);

// DELETE
$count = $this->users->delete(['id' => 1]);
```

---

## Advanced Queries

The `retrieve()` and `retrievePaginate()` methods accept an `$options` array to build complex queries without writing boilerplate.

```php
$users = $this->users->retrieve(['id', 'name', 'email'], [
    // Filtering (requires EloquentFilter on Model)
    'filters' => ['status' => 'active', 'role' => 'admin'],
    
    // Scopes
    'scopes' => ['verified', 'olderThan' => [18]],
    
    // Relationships
    'with' => ['profile', 'posts'],
    'with_count' => ['posts'],
    
    // Sorting
    'sort' => 'created_at',
    'direction' => 'desc',
    
    // Pagination (for retrievePaginate)
    'per_page' => 25,
    
    // Limits & Offsets
    'limit' => 10,
    'offset' => 5,
    
    // Grouping
    'group_by' => ['status'],
    'distinct' => true,
]);
```

### Supported Options

| Option | Description | Example |
|--------|-------------|---------|
| `filters` | Apply Eloquent filters | `['status' => 'active']` |
| `scopes` | Apply local scopes | `['active', 'type' => ['admin']]` |
| `with` | Eager load relations | `['profile']` |
| `with_count` | Count relations | `['comments']` |
| `sort` | Order by column | `'created_at'` |
| `direction` | Order direction | `'desc'` |
| `per_page` | Items per page | `15` |
| `limit` | Limit results | `10` |
| `offset` | Offset results | `5` |
| `distinct` | Distinct selection | `true` |
| `joins` | Join tables | `['posts' => ['users.id', '=', 'posts.user_id']]` |

> [!NOTE]
> To use `filters`, your Eloquent Model must use the `Filterable` trait (typically from `tucker-eric/eloquentfilter`).


---

## Development

```bash
composer test          # Run tests
composer lint          # Fix code style
composer rector        # Apply refactorings
```

---

## Related Packages

| Package | Description |
|---------|-------------|
| [frontier/frontier](https://github.com/0xKhdr/frontier) | Laravel Starter Kit |
| [frontier/action](https://github.com/0xKhdr/frontier-action) | Action Pattern |
| [frontier/module](https://github.com/0xKhdr/frontier-module) | Modular Architecture |

---



## Contributors

- [Mohamed Khedr](mailto:0xkhdr@gmail.com)

---

## License

MIT License. See [LICENSE](LICENSE) for details.

---

<p align="center">
  Made with ❤️ for Laravel community
</p>
