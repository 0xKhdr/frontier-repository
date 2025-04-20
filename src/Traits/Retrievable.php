<?php

namespace Frontier\Repositories\Traits;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait Retrievable
{
    // 1. Primary query builder method (entry point)
    protected function getRetrieveQuery(array $columns = ['*'], array $options = []): Builder
    {
        $this->resetBuilder();

        return $this->select(columns: $columns)
            ->filters(
                filters: Arr::except(
                    Arr::get($options, 'filters', []),
                    ['sort', 'direction', 'offset', 'per_page']
                )
            )
            ->scopes(
                scopes: Arr::get($options, 'scopes')
            )
            ->joins(
                joins: Arr::get($options, 'joins')
            )
            ->groupBy(
                groups: Arr::get($options, 'group_by')
            )
            ->distinct(
                distinct: Arr::get($options, 'distinct', false)
            )
            ->sort(
                sort: Arr::get(
                    $options,
                    'filters.sort',
                    config('app.order_by.column')
                ),
                direction: Arr::get(
                    $options,
                    'filters.direction',
                    config('app.order_by.direction')
                )
            )
            ->offset(
                offset: Arr::get($options, 'filters.offset'),
                limit: Arr::get($options, 'filters.per_page', -1)
            )
            ->with(
                relations: Arr::get($options, 'with')
            )
            ->getBuilder();
    }

    // 2. Core query components
    protected function select(array $columns = ['*']): static
    {
        $safeColumns = array_map(function ($column) {
            if (Str::contains($column, '(') && Str::contains($column, ')')) {
                return $this->validateRawExpression($column);
            }

            return $this->prefixTable($column);
        }, $columns);

        $this->builder->selectRaw(implode(',', $safeColumns));

        return $this;
    }

    protected function where(?array $conditions): static
    {
        if ($conditions) {
            $this->builder->where($conditions);
        }

        return $this;
    }

    // 3. Filtering and scoping
    protected function filters(?array $filters): static
    {
        if ($filters) {
            $this->builder->filter($filters);
        }

        return $this;
    }

    protected function scopes(?array $scopes): static
    {
        if ($scopes) {
            foreach ($scopes as $scope => $parameters) {
                is_numeric($scope)
                    ? $this->builder->{$parameters}()
                    : $this->builder->{$scope}(...$parameters);
            }
        }

        return $this;
    }

    // 4. Joins and relationships
    protected function joins(?array $joins): static
    {
        if ($joins) {
            foreach ($joins as $join => $parameters) {
                is_numeric($join)
                    ? $this->builder->{$parameters}()
                    : $this->builder->{$join}(...$parameters);
            }
        }

        return $this;
    }

    protected function with(?array $relations): static
    {
        if ($relations) {
            $this->builder->with($relations);
        }

        return $this;
    }

    // 5. Grouping and distinct
    protected function groupBy(?array $groups): static
    {
        if ($groups) {
            $this->builder->groupBy($groups);
        }

        return $this;
    }

    protected function distinct(bool $distinct): static
    {
        if ($distinct) {
            $this->builder->distinct();
        }

        return $this;
    }

    // 6. Sorting and pagination
    protected function sort(string|array|null $sort, string|array|null $direction): static
    {
        if ($sort) {
            $sortArray = is_array($sort) ? $sort : [$sort];
            $directionArray = is_array($direction) ? $direction : [$direction];

            foreach ($sortArray as $key => $sort) {
                $this->builder->orderBy(
                    $this->prefixTable($sort),
                    Arr::get($directionArray, $key, $direction ?? 'asc')
                );
            }
        }

        return $this;
    }

    protected function offset(?int $offset, int $limit): static
    {
        if ($offset) {
            $this->builder->offset($offset);
        }
        $this->builder->limit($limit);

        return $this;
    }

    // 7. Utility methods
    protected function prefixTable(string $column): string
    {
        if (Str::contains($column, '.')) {
            return $column;
        }

        if (Str::startsWith($column, '@')) {
            return Str::afterLast($column, '@');
        }

        return $this->getModel()->getTable().'src'.$column;
    }

    protected function validateRawExpression(string $expression): string
    {
        if (preg_match('/\b(delete|update|insert|drop|alter)\b/i', $expression)) {
            throw new InvalidArgumentException('Potentially dangerous raw expression');
        }

        return $expression;
    }
}
