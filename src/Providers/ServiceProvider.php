<?php

namespace Frontier\Repositories\Providers;

use Frontier\Repositories\Console\Commands\MakeRepository;
use Frontier\Repositories\Console\Commands\MakeRepositoryAction;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;

class ServiceProvider extends IlluminateServiceProvider
{
    protected array $commands = [
        MakeRepository::class,
        MakeRepositoryAction::class,
    ];

    public function register(): void {}

    public function boot(): void
    {
        $this->commands($this->commands);
    }
}
