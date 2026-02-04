<?php

declare(strict_types=1);

namespace Frontier\Repositories\Traits;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Provides fluent query building methods for repositories.
 *
 * All methods create fresh builders to ensure query isolation.
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
        $query = $this->newQuery();

        return $this->applyQueryOptions($query, $columns, $options)
            ->when(Arr::get($options, 'limit'), fn (Builder $q, int $limit) => $q->limit($limit))
            ->when(Arr::get($options, 'offset'), fn (Builder $q, int $offset) => $q->offset($offset));
    }

    /**
     * Build query for pagination methods (NO offset/limit - paginator handles it).
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    protected function getRetrieveQueryForPagination(array $columns = ['*'], array $options = []): Builder
    {
        return $this->applyQueryOptions($this->newQuery(), $columns, $options);
    }

    /**
     * Apply all query options to a builder.
     *
     * @param  array<int, string>  $columns  Columns to select
     * @param  array<string, mixed>  $options  Query options
     */
    private function applyQueryOptions(Builder $query, array $columns = ['*'], array $options = []): Builder
    {
        $query->select($this->prefixColumns($columns));

        // Apply filters
        if ($filters = Arr::get($options, 'filters')) {
            $query->filter($filters);
        }

        // Apply scopes
        if ($scopes = Arr::get($options, 'scopes')) {
            foreach ($scopes as $scope => $parameters) {
                is_numeric($scope)
                    ? $query->{$parameters}()
                    : $query->{$scope}(...$parameters);
            }
        }

        // Apply joins
        if ($joins = Arr::get($options, 'joins')) {
            foreach ($joins as $join => $parameters) {
                is_numeric($join)
                    ? $query->{$parameters}()
                    : $query->{$join}(...$parameters);
            }
        }

        // Apply group by
        if ($groups = Arr::get($options, 'group_by')) {
            $query->groupBy($groups);
        }

        // Apply distinct
        if (Arr::get($options, 'distinct', false)) {
            $query->distinct();
        }

        // Apply sorting
        $this->applyOrder(
            $query,
            Arr::get($options, 'sort') ?? config('app.default_order.sort'),
            Arr::get($options, 'direction') ?? config('app.default_order.direction')
        );

        // Eager load relationships
        if ($relations = Arr::get($options, 'with')) {
            $query->with($relations);
        }

        // Load relationship counts
        if ($withCount = Arr::get($options, 'with_count')) {
            $query->withCount($withCount);
        }

        return $query;
    }

    /**
     * Apply sorting to query.
     *
     * Supports:
     * - Single column: sort('name', 'asc')
     * - Multiple columns: sort(['name', 'created_at'], ['asc', 'desc'])
     * - Raw expressions: sort('raw:FIELD(status, "active", "pending", "closed")', 'asc')
     *
     * @param  string|array<int, string>|null  $sort  Sort column(s) or raw expressions prefixed with 'raw:'
     * @param  string|array<int, string>|null  $direction  Sort direction(s) - only 'asc' or 'desc' allowed
     */
    private function applyOrder(Builder $query, string|array|null $sort, string|array|null $direction): void
    {
        if ($sort === null) {
            return;
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
                $query->orderByRaw($this->validateRawExpression($raw).' '.$dir);
            } else {
                $query->orderBy($this->prefixColumn($sortColumn), $dir);
            }
        }
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
     * Prefix columns with table name.
     *
     * @param  array<int, string>  $columns  Columns to prefix
     * @return array<int, string> Prefixed columns
     */
    protected function prefixColumns(array $columns): array
    {
        return array_map(function ($column) {
            if ($column instanceof \Illuminate\Contracts\Database\Query\Expression) {
                return $column;
            }

            if (Str::contains($column, '(') && Str::contains($column, ')')) {
                return $this->validateRawExpression($column);
            }

            return $this->prefixColumn($column);
        }, $columns);
    }

    /**
     * Prefix a single column with table name if needed.
     */
    protected function prefixColumn(string $column): string
    {
        // Validate column name
        if (! preg_match('/^[a-zA-Z0-9_\.\*]+(\s+as\s+\w+)?$/', $column)) {
            throw new InvalidArgumentException("Invalid column name: {$column}");
        }

        // Already has table prefix
        if (Str::contains($column, '.')) {
            return $column;
        }

        // Explicit no-prefix marker
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
