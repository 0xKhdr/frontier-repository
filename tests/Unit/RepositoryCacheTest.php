<?php

declare(strict_types=1);

use Frontier\Repositories\BaseRepositoryCache;
use Frontier\Repositories\Contracts\Repository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

describe('RepositoryCache', function (): void {
    // Allow Log::warning() to be called without failing on tests that exercise
    // write operations against non-tag drivers (clearCache side-effect).
    beforeEach(fn () => Log::spy());

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

    it('invalidates cache on deleteByIds', function (): void {
        Config::set('repository-cache.enabled', true);

        $innerRepo = Mockery::mock(Repository::class);
        $cacheStore = Mockery::mock(CacheContract::class);

        Cache::shouldReceive('store')->andReturn($cacheStore);

        $innerRepo->shouldReceive('getTable')->andReturn('users');
        $innerRepo->shouldReceive('deleteByIds')->once()->with([1, 2])->andReturn(2);

        $cacheStore->shouldReceive('supportsTags')->andReturn(false);

        $decorator = new BaseRepositoryCache($innerRepo);
        $result = $decorator->deleteByIds([1, 2]);

        expect($result)->toBe(2);
    });

    it('returns false and logs a warning when clearCache is called on a non-tag driver', function (): void {
        Config::set('repository-cache.enabled', true);

        $innerRepo = Mockery::mock(Repository::class);
        $cacheStore = Mockery::mock(CacheContract::class);

        Cache::shouldReceive('store')->andReturn($cacheStore);
        $innerRepo->shouldReceive('getTable')->andReturn('users');
        $cacheStore->shouldReceive('supportsTags')->andReturn(false);

        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'does not support tags')
                && isset($context['driver'])
                && isset($context['prefix']);
        });

        $decorator = new BaseRepositoryCache($innerRepo);
        $result = $decorator->clearCache();

        expect($result)->toBeFalse();
    });

    it('returns true and flushes tagged cache when driver supports tags', function (): void {
        Config::set('repository-cache.enabled', true);

        $innerRepo = Mockery::mock(Repository::class);
        $cacheStore = Mockery::mock(CacheContract::class);

        $innerRepo->shouldReceive('getTable')->andReturn('users');
        $cacheStore->shouldReceive('supportsTags')->andReturn(true);
        $cacheStore->shouldReceive('tags')->with(['users'])->andReturnSelf();
        $cacheStore->shouldReceive('flush')->once()->andReturn(true);

        Cache::shouldReceive('store')->andReturn($cacheStore);

        $decorator = new BaseRepositoryCache($innerRepo);
        $result = $decorator->clearCache();

        expect($result)->toBeTrue();
    });
});
