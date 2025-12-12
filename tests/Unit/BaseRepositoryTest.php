<?php

declare(strict_types=1);

use Frontier\Repositories\BaseRepository;
use Frontier\Repositories\Contracts\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

describe('BaseRepository', function (): void {
    it('implements Repository contract', function (): void {
        $model = Mockery::mock(Model::class);
        $builder = Mockery::mock(Builder::class);
        $model->shouldReceive('newQuery')->andReturn($builder);
        
        $repository = new class($model) extends BaseRepository {};

        expect($repository)->toBeInstanceOf(Repository::class);
    });
});
