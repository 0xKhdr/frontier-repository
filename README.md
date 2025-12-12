<p align="center">
  <h1 align="center">Frontier Repository</h1>
  <p align="center">
    <strong>Repository Pattern implementation for Laravel applications</strong>
  </p>
</p>

<p align="center">
  <a href="#installation">Installation</a> •
  <a href="#quick-start">Quick Start</a> •
  <a href="#usage">Usage</a> •
  <a href="#api-reference">API Reference</a> •
  <a href="#artisan-commands">Commands</a>
</p>

<p align="center">
  <img src="https://img.shields.io/packagist/v/frontier/repository" alt="Latest Version">
  <img src="https://img.shields.io/packagist/php-v/frontier/repository" alt="PHP Version">
  <img src="https://img.shields.io/badge/Laravel-10.x%20|%2011.x%20|%2012.x-red" alt="Laravel Version">
  <img src="https://img.shields.io/packagist/l/frontier/repository" alt="License">
</p>

---

## About

**Frontier Repository** provides a clean abstraction layer between your business logic and Eloquent ORM using the Repository Pattern. It's a companion package to [frontier/frontier](https://github.com/frontier/frontier) (Laravel Starter Kit) and integrates seamlessly with [frontier/action](https://github.com/frontier/action).

### Features

- ✅ **Separation of concerns** — Decouples business logic from data access
- ✅ **Full CRUD operations** — Create, Read, Update, Delete with consistent API
- ✅ **Advanced querying** — Built-in filtering, sorting, pagination, scopes, and joins
- ✅ **Action integration** — Pre-built actions for common repository operations
- ✅ **Testability** — Easy to mock repositories in unit tests
- ✅ **Artisan generators** — Scaffold repositories and actions with commands

---

## Installation

```bash
composer require frontier/repository
```

The package auto-registers its service provider via Laravel's package discovery.

### Requirements

- PHP 8.2+
- Laravel 10.x, 11.x, or 12.x
- [frontier/action](https://github.com/frontier/action) ^1.0

---

## Quick Start

### 1. Generate a Repository

```bash
php artisan frontier:repository UserRepository
```

### 2. Add Constructor with Model

```php
<?php

namespace App\Repositories;

use App\Models\User;
use Frontier\Repositories\RepositoryEloquent as FrontierRepository;

class UserRepository extends FrontierRepository
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }
}
```

### 3. Use in Controller

```php
<?php

namespace App\Http\Controllers;

use App\Repositories\UserRepository;

class UserController extends Controller
{
    public function __construct(
        protected UserRepository $users
    ) {}

    public function index()
    {
        return $this->users->retrievePaginate(['*'], [
            'per_page' => 15,
            'sort' => 'created_at',
            'direction' => 'desc',
        ]);
    }

    public function show(int $id)
    {
        return $this->users->findOrFail(['id' => $id]);
    }

    public function store(Request $request)
    {
        return $this->users->create($request->validated());
    }

    public function update(Request $request, int $id)
    {
        return $this->users->update(
            ['id' => $id],
            $request->validated()
        );
    }

    public function destroy(int $id)
    {
        return $this->users->delete(['id' => $id]);
    }
}
```

---

## Usage

### Basic CRUD Operations

```php
// CREATE - Returns the created Model
$user = $this->users->create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
]);

// READ - Find single record (returns null if not found)
$user = $this->users->find(['id' => 1]);

// READ - Find or throw ModelNotFoundException
$user = $this->users->findOrFail(['email' => 'john@example.com']);

// READ - Get all records
$users = $this->users->retrieve();

// READ - Paginated results
$users = $this->users->retrievePaginate(['*'], ['per_page' => 15]);

// UPDATE - Returns number of affected rows
$count = $this->users->update(
    ['id' => 1],
    ['name' => 'Jane Doe']
);

// UPDATE OR CREATE
$user = $this->users->updateOrCreate(
    ['email' => 'john@example.com'],
    ['name' => 'John Updated']
);

// DELETE - Returns number of deleted rows
$count = $this->users->delete(['id' => 1]);
```

### Advanced Querying

The `retrieve()` and `retrievePaginate()` methods accept an options array:

```php
$users = $this->users->retrieve(['id', 'name', 'email'], [
    // Filter records
    'filters' => [
        'status' => 'active',
        'role' => 'admin',
    ],
    
    // Apply model scopes
    'scopes' => [
        'verified',              // Calls $model->verified()
        'olderThan' => [18],     // Calls $model->olderThan(18)
    ],
    
    // Custom joins
    'joins' => [
        'withProfiles',          // Calls $model->withProfiles()
    ],
    
    // Eager load relationships
    'with' => ['profile', 'roles'],
    
    // Sorting (single or multiple)
    'sort' => 'created_at',
    'direction' => 'desc',
    
    // Or multiple columns
    'sort' => ['created_at', 'name'],
    'direction' => ['desc', 'asc'],
    
    // Grouping
    'group_by' => ['department_id'],
    
    // Distinct results
    'distinct' => true,
    
    // Offset/limit
    'offset' => 10,
    'per_page' => 25,
]);
```

### Bulk Operations

```php
// Insert multiple records
$this->users->insert([
    ['name' => 'User 1', 'email' => 'user1@example.com'],
    ['name' => 'User 2', 'email' => 'user2@example.com'],
]);

// Insert and get ID
$id = $this->users->insertGetId([
    'name' => 'New User',
    'email' => 'new@example.com',
]);

// Upsert (insert or update)
$this->users->upsert(
    values: [
        ['email' => 'john@example.com', 'name' => 'John Updated'],
        ['email' => 'jane@example.com', 'name' => 'Jane New'],
    ],
    uniqueBy: ['email'],
    update: ['name']
);

// Process in chunks (memory efficient)
$this->users->chunk(100, function ($users) {
    foreach ($users as $user) {
        // Process each user
    }
});
```

### Transactions

```php
$result = $this->users->transaction(function () use ($userData, $profileData) {
    $user = $this->users->create($userData);
    $this->profiles->create([...$profileData, 'user_id' => $user->id]);
    
    return $user;
});
```

### Utility Methods

```php
// Count records
$count = $this->users->count(['status' => 'active']);

// Check existence
$exists = $this->users->exists(['email' => 'john@example.com']);

// First or create
$user = $this->users->firstOrCreate(
    ['email' => 'john@example.com'],
    ['name' => 'John Doe']
);

// Get underlying model
$model = $this->users->getModel();

// Get table name
$table = $this->users->getTable();

// Get query builder
$builder = $this->users->getBuilder();
```

---

## Interface Binding

For better testability, bind your repositories to interfaces:

```php
// app/Repositories/Contracts/UserRepositoryInterface.php
<?php

namespace App\Repositories\Contracts;

use Frontier\Repositories\Contracts\RepositoryEloquent;

interface UserRepositoryInterface extends RepositoryEloquent
{
    public function findActiveUsers(): Collection;
}
```

```php
// app/Providers/RepositoryServiceProvider.php
<?php

namespace App\Providers;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\UserRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            UserRepositoryInterface::class,
            UserRepository::class
        );
    }
}
```

---

## Repository Actions

### Create Custom Actions

```bash
php artisan frontier:repository-action CreateUser
```

```php
<?php

namespace App\Actions;

use App\Repositories\UserRepository;
use Frontier\Repositories\RepositoryAction as FrontierAction;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends FrontierAction
{
    public function __construct(UserRepository $repository)
    {
        $this->repository = $repository;
    }

    public function handle(array $data): Model
    {
        $data['password'] = bcrypt($data['password']);
        
        return $this->repository->create($data);
    }
}
```

### Use in Controller

```php
public function store(Request $request, CreateUser $action): User
{
    return $action->handle($request->validated());
}
```

### Built-in Actions

| Action | Description |
|--------|-------------|
| `CreateAction` | Create a new record |
| `RetrieveAction` | Retrieve records (with optional pagination) |
| `FindAction` | Find single record |
| `FindOrFailAction` | Find or throw exception |
| `UpdateAction` | Update matching records |
| `UpdateOrCreateAction` | Update or create record |
| `DeleteAction` | Delete matching records |
| `CountAction` | Count matching records |
| `ExistsAction` | Check if records exist |

---

## Customization

### Custom Base Repository

```php
<?php

namespace App\Repositories;

use Frontier\Repositories\RepositoryEloquent;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository extends RepositoryEloquent
{
    // Add tenant scoping
    public function create(array $values): Model
    {
        $values['tenant_id'] = tenant()->id;
        
        return parent::create($values);
    }

    // Add audit trail
    public function update(array $conditions, array $values): int
    {
        $values['updated_by'] = auth()->id();
        
        return parent::update($conditions, $values);
    }

    // Add soft delete support
    public function restore(array $conditions): bool
    {
        return $this->where($conditions)
            ->getBuilder()
            ->restore();
    }
}
```

### Custom Query Methods

```php
class UserRepository extends RepositoryEloquent
{
    public function findActiveAdmins(): Collection
    {
        return $this->retrieve(['*'], [
            'filters' => ['is_active' => true],
            'scopes' => ['admin'],
            'sort' => 'name',
        ]);
    }

    public function searchByName(string $name): Collection
    {
        return $this->getBuilder()
            ->where('name', 'LIKE', "%{$name}%")
            ->get();
    }
}
```

---

## Artisan Commands

| Command | Description |
|---------|-------------|
| `php artisan frontier:repository {name}` | Create a new repository class |
| `php artisan frontier:repository-action {name}` | Create a repository action class |

### Examples

```bash
# Create repository
php artisan frontier:repository UserRepository
# → Creates: app/Repositories/UserRepository.php

# Create repository action
php artisan frontier:repository-action RegisterUser
# → Creates: app/Actions/RegisterUser.php
```

---

## API Reference

### RepositoryEloquent Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `create(array $values)` | `Model` | Create new record |
| `update(array $conditions, array $values)` | `int` | Update matching records |
| `delete(array $conditions)` | `int` | Delete matching records |
| `insert(array $values)` | `bool` | Bulk insert records |
| `insertGetId(array $values)` | `int` | Insert and get ID |
| `upsert(array $values, array $uniqueBy, ?array $update)` | `int` | Insert or update |
| `retrieve(array $columns, array $options)` | `Collection` | Get all matching records |
| `retrievePaginate(array $columns, array $options, ...)` | `LengthAwarePaginator` | Paginated results |
| `find(array $conditions, array $columns)` | `?Model` | Find first match |
| `findOrFail(array $conditions, array $columns)` | `Model` | Find or throw |
| `updateOrCreate(array $conditions, array $values)` | `Model` | Update or create |
| `firstOrCreate(array $conditions, array $values)` | `Model` | Get or create |
| `count(array $conditions)` | `int` | Count matches |
| `exists(array $conditions)` | `bool` | Check existence |
| `chunk(int $count, callable $callback)` | `bool` | Process in batches |
| `transaction(callable $callback)` | `mixed` | Database transaction |
| `getModel()` | `Model` | Get underlying model |
| `getTable()` | `string` | Get table name |
| `getBuilder()` | `Builder` | Get query builder |
| `resetBuilder()` | `static` | Reset query builder |
| `withBuilder(Builder $builder)` | `static` | Set custom builder |

---

## Related Packages

| Package | Description |
|---------|-------------|
| [frontier/frontier](https://github.com/frontier/frontier) | Laravel Starter Kit |
| [frontier/action](https://github.com/frontier/action) | Action Pattern for Laravel |

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
