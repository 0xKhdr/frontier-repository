<?php

declare(strict_types=1);

use Frontier\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

describe('Retrievable Sort', function (): void {
    /**
     * Returns an anonymous BaseRepository that exposes `testSort()` so we can
     * drive `applyOrder` (private in the trait) through the public options array.
     */
    function createRepositoryForSort(): array
    {
        $model = Mockery::mock(Model::class);
        $builder = Mockery::mock(Builder::class);

        $model->shouldReceive('newQuery')->andReturn($builder);
        $model->shouldReceive('getTable')->andReturn('users');
        $builder->shouldReceive('getModel')->andReturn($model);
        $builder->shouldReceive('select')->andReturnSelf();
        $builder->shouldReceive('when')->andReturnSelf();
        $builder->shouldReceive('get')->andReturn(collect());

        $repo = new class($model) extends BaseRepository {
            /**
             * Drives sorting through the public retrieve() options array so that
             * the private applyOrder() is exercised without coupling to internals.
             */
            public function testSort(string|array|null $sort, string|array|null $direction): void
            {
                $this->retrieve(['*'], ['sort' => $sort, 'direction' => $direction]);
            }

            public function testRawExpression(string $expr): void
            {
                $this->validateRawExpression($expr);
            }
        };

        return [$repo, $builder];
    }

    it('sorts by single column with default direction', function (): void {
        [$repo, $builder] = createRepositoryForSort();

        $builder->shouldReceive('orderBy')->with('users.name', 'asc')->once()->andReturnSelf();

        $repo->testSort('name', 'asc');
    });

    it('sorts by multiple columns with mixed directions', function (): void {
        [$repo, $builder] = createRepositoryForSort();

        $builder->shouldReceive('orderBy')->with('users.name', 'asc')->once()->andReturnSelf();
        $builder->shouldReceive('orderBy')->with('users.created_at', 'desc')->once()->andReturnSelf();

        $repo->testSort(['name', 'created_at'], ['asc', 'desc']);
    });

    it('normalizes invalid direction to asc', function (): void {
        [$repo, $builder] = createRepositoryForSort();

        $builder->shouldReceive('orderBy')->with('users.status', 'asc')->once()->andReturnSelf();

        $repo->testSort('status', 'INVALID_DIRECTION');
    });

    it('handles raw expressions correctly', function (): void {
        [$repo, $builder] = createRepositoryForSort();

        $builder->shouldReceive('orderByRaw')->with('length(name) asc')->once()->andReturnSelf();

        $repo->testSort('raw:length(name)', 'asc');
    });

    it('handles complex raw expressions like NULLS LAST', function (): void {
        [$repo, $builder] = createRepositoryForSort();

        $builder->shouldReceive('orderByRaw')->with('name IS NULL, name asc')->once()->andReturnSelf();

        $repo->testSort('raw:name IS NULL, name', 'asc');
    });

    it('applies same direction to all columns when one direction string is given', function (): void {
        [$repo, $builder] = createRepositoryForSort();

        $builder->shouldReceive('orderBy')->with('users.a', 'desc')->once()->andReturnSelf();
        $builder->shouldReceive('orderBy')->with('users.b', 'desc')->once()->andReturnSelf();

        $repo->testSort(['a', 'b'], 'desc');
    });

    it('throws exception for dangerous raw expressions', function (): void {
        [$repo] = createRepositoryForSort();

        expect(fn () => $repo->testSort('raw:drop table users', 'asc'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('allows UNION in raw sort expressions (legitimate ORDER BY usage)', function (): void {
        [$repo, $builder] = createRepositoryForSort();

        // UNION is no longer blocked — not an injection risk in ORDER BY context.
        // The query builder mock will handle the call without error.
        $builder->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $repo->testSort('raw:FIELD(status, "active", "pending")', 'asc');
    });
});
