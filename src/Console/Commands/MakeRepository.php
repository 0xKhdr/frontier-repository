<?php

namespace Frontier\Repositories\Console\Commands;

use Illuminate\Support\Facades\App;

class MakeRepository extends AbstractMake
{
    protected $signature = 'frontier:repository {name}';

    protected $description = 'Create a new repository class';

    public function getSourceFilePath(): string
    {
        return App::path('Repositories/'.$this->getClassName()).'.php';
    }

    public function getStubPath(): string
    {
        return __DIR__.'/../../../resources/stubs/repository.stub';
    }

    public function getStubVariables(): array
    {
        return [
            'NAMESPACE' => 'App\\Repositories',
            'CLASS_NAME' => $this->getClassName(),
        ];
    }
}
