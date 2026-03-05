<?php

declare(strict_types=1);

namespace Frontier\Repositories\Console\Commands;

/**
 * Artisan command to generate a new repository action class.
 */
class MakeRepositoryAction extends GeneratorCommand
{
    protected $signature = 'frontier:repository-action {name} {--module= : The module to create the action in}';

    protected $description = 'Create a new repository action class';

    public function getStubPath(): string
    {
        return __DIR__.'/../../../stubs/repository-action.stub';
    }

    protected function getSubNamespace(): string
    {
        return 'Actions';
    }
}
