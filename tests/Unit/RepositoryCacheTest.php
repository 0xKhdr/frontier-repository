<?php

declare(strict_types=1);

use Frontier\Repositories\BaseRepositoryCache;
use Frontier\Repositories\Contracts\Repository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

describe('RepositoryCache', function (): void {
    it('caches retrieve calls', function (): void {
        // Setup Config
        Config::set('repository-cache.enabled', true);
        Config::set('repository-cache.driver', 'array');

        // Mocks
        $innerRepo = Mockery::mock(Repository::class);
        $cacheStore = Mockery::mock(CacheContract::class);

        // Setup Cache Facade
        Cache::shouldReceive('store')->andReturn($cacheStore);

        // Setup Repository Mock
        $innerRepo->shouldReceive('getTable')->andReturn('users');
        $innerRepo->shouldReceive('retrieve')->once()->andReturn(new Collection(['data']));

        // Setup Cache Store Mock
        $cacheStore->shouldReceive('supportsTags')->andReturn(false);
        $cacheStore->shouldReceive('forget')->never();
        $cacheStore->shouldReceive('remember')
            ->once()
            ->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

        // Test
        $decorator = new BaseRepositoryCache($innerRepo);
        $result = $decorator->retrieve();

        expect($result)->toBeInstanceOf(Collection::class);
    });

    it('invalidates cache on create', function (): void {
        // Setup Config
        Config::set('repository-cache.enabled', true);

        // Mocks
        $innerRepo = Mockery::mock(Repository::class);
        $cacheStore = Mockery::mock(CacheContract::class);

        // Setup Cache Facade
        Cache::shouldReceive('store')->andReturn($cacheStore);

        // Setup Repository Mock
        $innerRepo->shouldReceive('getTable')->andReturn('users');
        $innerRepo->shouldReceive('create')->once()->andReturn(Mockery::mock(Model::class));

        // Setup Cache Store Mock
        $cacheStore->shouldReceive('supportsTags')->andReturn(false);

        $decorator = new BaseRepositoryCache($innerRepo);
        $decorator->create([]);
    });
});
