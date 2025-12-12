<?php

declare(strict_types=1);

namespace Frontier\Repositories\Providers;

use Frontier\Repositories\Console\Commands\MakeCacheableRepository;
use Frontier\Repositories\Console\Commands\MakeRepository;
use Frontier\Repositories\Console\Commands\MakeRepositoryAction;
use Frontier\Repositories\Console\Commands\MakeRepositoryInterface;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;

/**
 * Frontier Repositories package service provider.
 */
class ServiceProvider extends IlluminateServiceProvider
{
    /** @var array<int, class-string> */
    protected array $commands = [
        MakeRepository::class,
        MakeCacheableRepository::class,
        MakeRepositoryAction::class,
        MakeRepositoryInterface::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/repository-cache.php',
            'repository-cache'
        );
    }

    public function boot(): void
    {
        $this->commands($this->commands);

        $this->publishes([
            __DIR__.'/../../config/repository-cache.php' => config_path('repository-cache.php'),
        ], 'repository-config');
    }
}
