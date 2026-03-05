<?php

declare(strict_types=1);

namespace Frontier\Repositories\Console\Commands;

/**
 * Artisan command to generate a new cacheable repository class.
 */
class MakeRepositoryCache extends GeneratorCommand
{
    protected $signature = 'frontier:repository-cache {name} {--module= : The module to create the repository in}';

    protected $description = 'Create a new cacheable repository class';

    public function getStubPath(): string
    {
        return __DIR__.'/../../../stubs/repository-cache.stub';
    }

    protected function getSubNamespace(): string
    {
        return 'Repositories\\Cache';
    }
}
