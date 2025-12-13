<?php

declare(strict_types=1);

use Frontier\Repositories\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;



describe('MakeRepository Command', function (): void {
    it('is registered', function (): void {
        $commands = $this->app[Kernel::class]->all();
        expect($commands)->toHaveKey('frontier:repository');
    });
});

describe('MakeRepositoryCache Command', function (): void {
    it('is registered', function (): void {
        $commands = $this->app[Kernel::class]->all();
        expect($commands)->toHaveKey('frontier:repository-cache');
    });
});

describe('MakeRepositoryAction Command', function (): void {
    it('is registered', function (): void {
        $commands = $this->app[Kernel::class]->all();
        expect($commands)->toHaveKey('frontier:repository-action');
    });
});
