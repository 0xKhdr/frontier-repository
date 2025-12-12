<?php

declare(strict_types=1);

use Frontier\Repositories\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;

uses(TestCase::class);

describe('MakeRepository Command', function (): void {
    it('is registered', function (): void {
        $commands = $this->app[Kernel::class]->all();
        expect($commands)->toHaveKey('frontier:repository');
    });
});

describe('MakeCacheableRepository Command', function (): void {
    it('is registered', function (): void {
        $commands = $this->app[Kernel::class]->all();
        expect($commands)->toHaveKey('frontier:cacheable-repository');
    });
});

describe('MakeRepositoryAction Command', function (): void {
    it('is registered', function (): void {
        $commands = $this->app[Kernel::class]->all();
        expect($commands)->toHaveKey('frontier:repository-action');
    });
});
