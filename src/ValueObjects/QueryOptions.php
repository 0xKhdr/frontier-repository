<?php

declare(strict_types=1);

namespace Frontier\Repositories\ValueObjects;

/**
 * Strongly-typed query options for repository retrieval methods.
 *
 * Provides IDE autocompletion and compile-time discoverability of all available
 * query options as an alternative to the untyped `array $options` parameter.
 *
 * Compatible with all methods accepting `$options` in BaseRepository.
 * Convert to array via toArray() or pass directly — repository methods accept
 * `array|QueryOptions $options` as a union type.
 *
 * @example
 * ```php
 * $options = new QueryOptions(
 *     sort: 'created_at',
 *     direction: 'desc',
 *     with: ['profile', 'roles'],
 *     limit: 20,
 * );
 *
 * $users = $userRepository->get(['*'], $options);
 * $users = $userRepository->getBy(['status' => 'active'], ['*'], $options);
 * ```
 */
final class QueryOptions
{
    /**
     * @param  array<string, mixed>  $filters  EloquentFilter filters
     *                                         (requires Filterable trait on model)
     * @param  array<int|string, mixed>  $scopes  Local scopes to apply:
     *                                            - Keyed: ['scopeName' => [$arg1, $arg2]]
     *                                            - Indexed: ['scopeName'] (no args)
     * @param  array<int|string, mixed>  $joins  Join scopes to apply (same format as scopes)
     * @param  string|array<int, string>|null  $sort  Column(s) to sort by.
     *                                                Prefix with 'raw:' for raw SQL expressions.
     * @param  string|array<int, string>|null  $direction  Sort direction(s): 'asc' or 'desc'.
     *                                                     Array must match length of $sort when both are arrays.
     * @param  string|array<int, string>|null  $groupBy  Column(s) to group by
     * @param  array<int|string, mixed>  $with  Eager-load relations (passed to Eloquent with())
     * @param  array<int, string>  $withCount  Relations to count (passed to Eloquent withCount())
     * @param  bool  $distinct  Apply SELECT DISTINCT
     * @param  int|null  $limit  Limit number of rows (get() only — ignored by pagination methods)
     * @param  int|null  $offset  Offset rows (get() only — ignored by pagination methods)
     */
    public function __construct(
        public readonly array $filters = [],
        public readonly array $scopes = [],
        public readonly array $joins = [],
        public readonly string|array|null $sort = null,
        public readonly string|array|null $direction = null,
        public readonly string|array|null $groupBy = null,
        public readonly array $with = [],
        public readonly array $withCount = [],
        public readonly bool $distinct = false,
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
    ) {}

    /**
     * Convert to the legacy array format accepted by Retrievable::applyQueryOptions().
     *
     * Keys with null or empty-array values are omitted from the output so they
     * do not override defaults already applied by the query builder.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $options = [];

        if ($this->filters !== []) {
            $options['filters'] = $this->filters;
        }

        if ($this->scopes !== []) {
            $options['scopes'] = $this->scopes;
        }

        if ($this->joins !== []) {
            $options['joins'] = $this->joins;
        }

        if ($this->sort !== null) {
            $options['sort'] = $this->sort;
        }

        if ($this->direction !== null) {
            $options['direction'] = $this->direction;
        }

        if ($this->groupBy !== null) {
            $options['group_by'] = $this->groupBy;
        }

        if ($this->with !== []) {
            $options['with'] = $this->with;
        }

        if ($this->withCount !== []) {
            $options['with_count'] = $this->withCount;
        }

        if ($this->distinct) {
            $options['distinct'] = true;
        }

        if ($this->limit !== null) {
            $options['limit'] = $this->limit;
        }

        if ($this->offset !== null) {
            $options['offset'] = $this->offset;
        }

        return $options;
    }
}
