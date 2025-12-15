<?php

declare(strict_types=1);

namespace Frontier\Repositories\Traits;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Provides fluent query building methods for repositories.
 */
trait Retrievable
{
    /**
     * Build base query with all options except offset/limit.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    private function buildBaseQuery(array $columns = ['*'], array $options = []): static
    {
        $this->resetBuilder();

        return $this->select(columns: $columns)
            ->filters(Arr::get($options, 'filters'))
            ->scopes(Arr::get($options, 'scopes'))
            ->joins(Arr::get($options, 'joins'))
            ->groupBy(Arr::get($options, 'group_by'))
            ->distinct(Arr::get($options, 'distinct', false))
            ->sort(
                sort: Arr::get($options, 'sort', config('app.order_by.column', 'id')),
                direction: Arr::get($options, 'direction', config('app.order_by.direction', 'desc'))
            )
            ->with(Arr::get($options, 'with'))
            ->withCount(Arr::get($options, 'with_count'));
    }

    /**
     * Build the retrieve query with all options applied.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    protected function getRetrieveQuery(array $columns = ['*'], array $options = []): Builder
    {
        return $this->buildBaseQuery($columns, $options)
            ->offset(
                limit: Arr::get($options, 'limit', -1),
                offset: Arr::get($options, 'offset')
            )
            ->getBuilder();
    }

    /**
     * Build query for pagination methods (NO offset/limit - paginator handles it).
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    protected function getRetrieveQueryForPagination(array $columns = ['*'], array $options = []): Builder
    {
        return $this->buildBaseQuery($columns, $options)->getBuilder();
    }

    /**
     * Select specific columns.
     *
     * @param  array<int, string>  $columns  Columns to select
     */
    protected function select(array $columns = ['*']): static
    {
        $safeColumns = array_map(function (string $column) {
            if (Str::contains($column, '(') && Str::contains($column, ')')) {
                return $this->validateRawExpression($column);
            }

            return $this->prefixTable($column);
        }, $columns);

        $this->builder->select($safeColumns);

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
     * Supports:
     * - Single column: sort('name', 'asc')
     * - Multiple columns: sort(['name', 'created_at'], ['asc', 'desc'])
     * - Raw expressions: sort('raw:FIELD(status, "active", "pending", "closed")', 'asc')
     * - NULLS LAST: sort('raw:column IS NULL, column', 'asc')
     *
     * @param  string|array<int, string>|null  $sort  Sort column(s) or raw expressions prefixed with 'raw:'
     * @param  string|array<int, string>|null  $direction  Sort direction(s) - only 'asc' or 'desc' allowed
     */
    protected function sort(string|array|null $sort, string|array|null $direction): static
    {
        if ($sort === null) {
            return $this;
        }

        $sortArray = (array) $sort;
        $defaultDirection = is_string($direction) ? $direction : 'asc';
        $directionArray = is_array($direction)
            ? $direction
            : array_fill(0, count($sortArray), $defaultDirection);

        foreach ($sortArray as $index => $sortColumn) {
            $dir = $this->normalizeDirection(Arr::get($directionArray, $index, $defaultDirection));

            if (Str::startsWith($sortColumn, 'raw:')) {
                $raw = Str::after($sortColumn, 'raw:');
                $this->builder->orderByRaw($this->validateRawExpression($raw).' '.$dir);
            } else {
                $this->builder->orderBy($this->prefixTable($sortColumn), $dir);
            }
        }

        return $this;
    }

    /**
     * Clear all existing order by clauses and optionally apply new sorting.
     *
     * Useful when you need to override sorting set by previous query building.
     *
     * @param  string|array<int, string>|null  $sort  Optional new sort column(s)
     * @param  string|array<int, string>|null  $direction  Optional new sort direction(s)
     */
    protected function reorder(string|array|null $sort = null, string|array|null $direction = null): static
    {
        $this->builder->reorder();

        if ($sort !== null) {
            $this->sort($sort, $direction);
        }

        return $this;
    }

    /**
     * Normalize and validate sort direction.
     *
     * Only allows 'asc' or 'desc' to prevent SQL injection.
     * Invalid values default to 'asc' for safety.
     */
    private function normalizeDirection(mixed $direction): string
    {
        $dir = strtolower(trim((string) $direction));

        return in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc';
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
        // Allow raw column if explicitly safe, otherwise validate
        if (! preg_match('/^[a-zA-Z0-9_\.\*]+(\s+as\s+\w+)?$/', $column)) {
            throw new InvalidArgumentException("Invalid column name: {$column}");
        }

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
