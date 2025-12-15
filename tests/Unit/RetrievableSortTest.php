<?php

declare(strict_types=1);

use Frontier\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

describe('Retrievable Sort', function (): void {
    // Helper to create repository with mocks
    function createRepositoryForSort(): BaseRepository
    {
        $model = Mockery::mock(Model::class);
        $builder = Mockery::mock(Builder::class);

        $model->shouldReceive('newQuery')->andReturn($builder);
        $model->shouldReceive('getTable')->andReturn('users');
        $builder->shouldReceive('getModel')->andReturn($model);

        return new class($model) extends BaseRepository
        {
            public function applySort(string|array|null $sort, string|array|null $direction): static
            {
                return $this->sort($sort, $direction);
            }

            public function applyReorder(string|array|null $sort = null, string|array|null $direction = null): static
            {
                return $this->reorder($sort, $direction);
            }
        };
    }

    it('sorts by single column with default direction', function (): void {
        $repo = createRepositoryForSort();
        $builder = $repo->getBuilder();

        $builder->shouldReceive('orderBy')->with('users.name', 'asc')->once()->andReturnSelf();

        $repo->applySort('name', 'asc');
    });

    it('sorts by multiple columns with mixed directions', function (): void {
        $repo = createRepositoryForSort();
        $builder = $repo->getBuilder();

        $builder->shouldReceive('orderBy')->with('users.name', 'asc')->once()->andReturnSelf();
        $builder->shouldReceive('orderBy')->with('users.created_at', 'desc')->once()->andReturnSelf();

        $repo->applySort(['name', 'created_at'], ['asc', 'desc']);
    });

    it('normalizes invalid direction to asc', function (): void {
        $repo = createRepositoryForSort();
        $builder = $repo->getBuilder();

        $builder->shouldReceive('orderBy')->with('users.status', 'asc')->once()->andReturnSelf();

        $repo->applySort('status', 'INVALID_DIRECTION');
    });

    it('handles raw expressions correctly', function (): void {
        $repo = createRepositoryForSort();
        $builder = $repo->getBuilder();

        // Expect orderByRaw with the expression and direction
        $builder->shouldReceive('orderByRaw')->with('length(name) asc')->once()->andReturnSelf();

        $repo->applySort('raw:length(name)', 'asc');
    });

    it('handles complex raw expressions like NULLS LAST', function (): void {
        $repo = createRepositoryForSort();
        $builder = $repo->getBuilder();

        $builder->shouldReceive('orderByRaw')->with('name IS NULL, name asc')->once()->andReturnSelf();

        $repo->applySort('raw:name IS NULL, name', 'asc');
    });

    it('fixes array fallback bug', function (): void {
        $repo = createRepositoryForSort();
        $builder = $repo->getBuilder();

        // If we pass array of sorts but ONE direction, it should use that direction for all
        // Or if direction is missing, default to asc
        $builder->shouldReceive('orderBy')->with('users.a', 'desc')->once()->andReturnSelf();
        $builder->shouldReceive('orderBy')->with('users.b', 'desc')->once()->andReturnSelf();

        $repo->applySort(['a', 'b'], 'desc');
    });

    it('reorders and then applies new sort', function (): void {
        $repo = createRepositoryForSort();
        $builder = $repo->getBuilder();

        $builder->shouldReceive('reorder')->once()->andReturnSelf();
        $builder->shouldReceive('orderBy')->with('users.id', 'desc')->once()->andReturnSelf();

        $repo->applyReorder('id', 'desc');
    });

    it('throws exception for dangerous raw expressions', function (): void {
        $repo = createRepositoryForSort();

        expect(fn () => $repo->applySort('raw:drop table users', 'asc'))
            ->toThrow(InvalidArgumentException::class);
    });
});
