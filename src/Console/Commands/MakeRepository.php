<?php

declare(strict_types=1);

namespace Frontier\Repositories\Console\Commands;

/**
 * Artisan command to generate a new repository class.
 */
class MakeRepository extends GeneratorCommand
{
    protected $signature = 'frontier:repository {name} {--module= : The module to create the repository in}';

    protected $description = 'Create a new repository class';

    public function getStubPath(): string
    {
        return __DIR__.'/../../../stubs/repository.stub';
    }

    protected function getSubNamespace(): string
    {
        return 'Repositories';
    }
}
