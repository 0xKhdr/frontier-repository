<?php

declare(strict_types=1);

namespace Frontier\Repositories\Console\Commands;

/**
 * Artisan command to generate a new repository interface.
 */
class MakeRepositoryInterface extends GeneratorCommand
{
    protected $signature = 'frontier:repository-interface {name} {--module= : The module to create the interface in}';

    protected $description = 'Create a new repository interface';

    public function getStubPath(): string
    {
        return __DIR__.'/../../../stubs/repository-interface.stub';
    }

    protected function getSubNamespace(): string
    {
        return 'Repositories\\Contracts';
    }
}
