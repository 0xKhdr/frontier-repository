<?php

declare(strict_types=1);

namespace Frontier\Repositories\Traits;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Provides fluent query building methods for repositories.
 */
trait Retrievable
{
    /**
     * Build the retrieve query with all options applied.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    protected function getRetrieveQuery(array $columns = ['*'], array $options = []): Builder
    {
        $this->resetBuilder();

        return $this->select(columns: $columns)
            ->filters(
                filters: Arr::get($options, 'filters')
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
                sort: Arr::get($options, 'sort', config('app.order_by.column')),
                direction: Arr::get($options, 'direction', config('app.order_by.direction'))
            )
            ->offset(
                limit: Arr::get($options, 'per_page', -1),
                offset: Arr::get($options, 'offset')
            )
            ->with(
                relations: Arr::get($options, 'with')
            )
            ->withCount(
                relations: Arr::get($options, 'with_count')
            )
            ->getBuilder();
    }

    /**
     * Select specific columns.
     *
     * @param  array<int, string>  $columns  Columns to select
     */
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

    /**
     * Apply where conditions.
     *
     * @param  array<string, mixed>|null  $conditions  Where conditions
     */
    protected function where(?array $conditions): static
    {
        if ($conditions) {
            $this->builder->where($conditions);
        }

        return $this;
    }

    /**
     * Apply model filters.
     *
     * @param  array<string, mixed>|null  $filters  Filter conditions
     */
    protected function filters(?array $filters): static
    {
        if ($filters) {
            $this->builder->filter($filters);
        }

        return $this;
    }

    /**
     * Apply model scopes.
     *
     * @param  array<string, mixed>|null  $scopes  Scopes to apply
     */
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

    /**
     * Apply join clauses.
     *
     * @param  array<string, mixed>|null  $joins  Joins to apply
     */
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

    /**
     * Eager load relationships.
     *
     * @param  array<int, string>|null  $relations  Relations to load
     */
    protected function with(?array $relations): static
    {
        if ($relations) {
            $this->builder->with($relations);
        }

        return $this;
    }

    /**
     * Load relationship counts.
     *
     * @param  array<int, string>|null  $relations  Relations to count
     */
    protected function withCount(?array $relations): static
    {
        if ($relations) {
            $this->builder->withCount($relations);
        }

        return $this;
    }

    /**
     * Group by columns.
     *
     * @param  array<int, string>|null  $groups  Columns to group by
     */
    protected function groupBy(?array $groups): static
    {
        if ($groups) {
            $this->builder->groupBy($groups);
        }

        return $this;
    }

    /**
     * Enable distinct results.
     */
    protected function distinct(bool $distinct): static
    {
        if ($distinct) {
            $this->builder->distinct();
        }

        return $this;
    }

    /**
     * Apply sorting.
     *
     * @param  string|array<int, string>|null  $sort  Sort column(s)
     * @param  string|array<int, string>|null  $direction  Sort direction(s)
     */
    protected function sort(string|array|null $sort, string|array|null $direction): static
    {
        if ($sort) {
            $sortArray = is_array($sort) ? $sort : [$sort];
            $directionArray = is_array($direction) ? $direction : [$direction];

            foreach ($sortArray as $key => $sortColumn) {
                $this->builder->orderBy(
                    $this->prefixTable($sortColumn),
                    Arr::get($directionArray, $key, $direction ?? 'asc')
                );
            }
        }

        return $this;
    }

    /**
     * Apply offset and limit.
     */
    protected function offset(int $limit, ?int $offset): static
    {
        if ($offset) {
            $this->builder->offset($offset);
        }
        $this->builder->limit($limit);

        return $this;
    }

    /**
     * Prefix column with table name if needed.
     */
    protected function prefixTable(string $column): string
    {
        if (Str::contains($column, '.')) {
            return $column;
        }

        if (Str::startsWith($column, '@')) {
            return Str::afterLast($column, '@');
        }

        return $this->getModel()->getTable().'.'.$column;
    }

    /**
     * Validate raw SQL expression for safety.
     *
     * @throws InvalidArgumentException
     */
    protected function validateRawExpression(string $expression): string
    {
        if (preg_match('/\b(delete|update|insert|drop|alter)\b/i', $expression)) {
            throw new InvalidArgumentException('Potentially dangerous raw expression');
        }

        return $expression;
    }
}
