<?php

namespace Frontier\Repositories;

use Frontier\Repositories\Traits\Retrievable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Throwable;

abstract class AbstractEloquentRepository extends AbstractRepository implements Contracts\EloquentRepository
{
    use Retrievable;

    protected Builder $builder;

    protected ?Builder $withBuilder = null;

    public function __construct(Model $model)
    {
        $this->builder = $model->newQuery();
    }

    public function create(array $values): Model
    {
        return tap($this->builder->create($values), function (Model $model) {
            $this->resetBuilder();
        });
    }

    public function retrieve(array $columns = ['*'], array $options = []): Collection
    {
        return $this->getRetrieveQuery($columns, $options)->get();
    }

    public function retrievePaginate(
        array $columns = ['*'],
        array $options = [],
        string $pageName = 'page',
        ?int $page = null
    ): LengthAwarePaginator {
        $perPage = intval(Arr::get($options, 'filters.per_page', $this->getModel()->getPerPage()));

        return $this->getRetrieveQuery($columns, $options)
            ->paginate($perPage, $columns, $pageName, $page);
    }

    public function find(array $conditions, array $columns = ['*']): ?Model
    {
        return $this->select($columns)
            ->where($conditions)
            ->getBuilder()
            ->first();
    }

    public function findOrFail(array $conditions, array $columns = ['*']): Model
    {
        return $this->select($columns)
            ->where($conditions)
            ->getBuilder()
            ->firstOrFail();
    }

    public function update(array $conditions, array $values): int
    {
        return $this->where($conditions)
            ->getBuilder()
            ->update($values);
    }

    public function updateOrCreate(array $conditions, array $values): Model
    {
        return tap($this->builder->updateOrCreate($conditions, $values), function () {
            $this->resetBuilder();
        });
    }

    public function delete(array $conditions): int
    {
        return (bool) $this->where($conditions)
            ->getBuilder()
            ->delete();
    }

    public function count(array $conditions = []): int
    {
        return $this->where($conditions)
            ->getBuilder()
            ->count();
    }

    public function exists(array $conditions): bool
    {
        return $this->where($conditions)
            ->getBuilder()
            ->exists();
    }

    public function insert(array $values): bool
    {
        return $this->builder->insert($values);
    }

    public function insertGetId(array $values): int
    {
        return $this->builder->insertGetId($values);
    }

    public function firstOrCreate(array $conditions, array $values = []): Model
    {
        return tap($this->builder->firstOrCreate($conditions, $values), function () {
            $this->resetBuilder();
        });
    }

    public function upsert(array $values, array $uniqueBy, ?array $update = null): int
    {
        return $this->builder->upsert($values, $uniqueBy, $update);
    }

    public function chunk(int $count, callable $callback): bool
    {
        return $this->builder->chunk($count, $callback);
    }

    /**
     * @throws Throwable
     */
    public function transaction(callable $callback): mixed
    {
        return $this->getModel()->getConnection()->transaction($callback);
    }

    public function resetBuilder(): static
    {
        $this->builder = $this->withBuilder
            ? $this->withBuilder->clone()
            : $this->getModel()->newQuery();

        return $this;
    }

    public function getTable(): string
    {
        return $this->getModel()->getTable();
    }

    public function getModel(): Model
    {
        return $this->builder->getModel();
    }

    public function withBuilder(Builder $builder): static
    {
        $this->withBuilder = $builder;

        return $this;
    }

    public function getBuilder(): Builder
    {
        return $this->builder;
    }
}
