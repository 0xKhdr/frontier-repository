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
     * DDL/DML keywords that must not appear in raw ORDER BY or SELECT expressions.
     *
     * These keywords are blocked as a defence-in-depth measure: Eloquent
     * parameterises bound values but raw expressions are interpolated as-is.
     * Word boundaries (\b) prevent false positives on column names that happen
     * to contain a keyword substring (e.g. "created_at" contains "create").
     *
     * Blocked keywords and rationale:
     *   delete, update, insert — DML; can modify data
     *   drop, alter, truncate  — DDL; can destroy schema
     *   exec, execute          — stored procedure / shell execution
     *   grant, revoke          — privilege escalation
     *
     * Intentionally NOT blocked:
     *   union  — not an injection risk in ORDER BY / SELECT context; needed for
     *            FIELD() expressions and subquery ordering patterns
     *   create — not dangerous in ORDER BY context; removing avoids false positives
     *            on raw expressions that happen to mention "create" as part of an alias
     */
    private const DANGEROUS_SQL_PATTERN = '/\b(delete|update|insert|drop|alter|truncate|exec|execute|grant|revoke)\b/i';

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
                if (is_numeric($scope)) {
                    $this->validateScopeName((string) $parameters);
                    $query->{$parameters}();
                } else {
                    $this->validateScopeName($scope);
                    $query->{$scope}(...$parameters);
                }
            }
        }

        // Apply joins
        if ($joins = Arr::get($options, 'joins')) {
            foreach ($joins as $join => $parameters) {
                if (is_numeric($join)) {
                    $this->validateScopeName((string) $parameters);
                    $query->{$parameters}();
                } else {
                    $this->validateScopeName($join);
                    $query->{$join}(...$parameters);
                }
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
            Arr::get($options, 'sort'),
            Arr::get($options, 'direction')
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
        // Validate column name (@ prefix is the explicit no-table-prefix marker)
        if (! preg_match('/^@?[a-zA-Z0-9_\.\*]+(\s+as\s+\w+)?$/', $column)) {
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
     * Validate that a scope or join name is a safe PHP identifier.
     *
     * Prevents invocation of arbitrary query builder methods if scope/join names
     * originate from untrusted sources. Scope names must match the pattern of a
     * valid PHP method name: start with a letter or underscore, contain only
     * alphanumeric characters and underscores.
     *
     * @throws InvalidArgumentException When the name contains invalid characters
     */
    private function validateScopeName(string $name): void
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Invalid scope or join name: {$name}");
        }
    }

    /**
     * Validate raw SQL expression for safety.
     *
     * Blocks a broad set of DDL/DML keywords that have no place in ORDER BY
     * or SELECT expressions. This is a defence-in-depth measure — Eloquent
     * parameterises bound values, but raw expressions are interpolated as-is.
     *
     * @throws InvalidArgumentException
     */
    protected function validateRawExpression(string $expression): string
    {
        if (preg_match(self::DANGEROUS_SQL_PATTERN, $expression)) {
            throw new InvalidArgumentException('Potentially dangerous raw expression');
        }

        return $expression;
    }
}
