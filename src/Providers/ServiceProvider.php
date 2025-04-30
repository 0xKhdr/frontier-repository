<?php

namespace Frontier\Repositories\Providers;

use Frontier\Actions\Console\Commands\MakeAction;
use Frontier\Repositories\Console\Commands\MakeRepository;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;

class ServiceProvider extends IlluminateServiceProvider
{
    protected array $commands = [
        MakeAction::class,
        MakeRepository::class,
    ];

    public function register(): void {}

    public function boot(): void
    {
        $this->commands($this->commands);
    }
}
