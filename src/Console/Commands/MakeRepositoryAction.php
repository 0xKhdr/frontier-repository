<?php

namespace Frontier\Repositories\Console\Commands;

use Illuminate\Support\Facades\App;

class MakeRepositoryAction extends AbstractMake
{
    protected $signature = 'frontier:repository-action {name}';

    protected $description = 'Create a new repository action class';

    public function getSourceFilePath(): string
    {
        return App::path('Actions/'.$this->getClassName()).'.php';
    }

    public function getStubPath(): string
    {
        return __DIR__.'/../../../resources/stubs/repository-action.stub';
    }

    public function getStubVariables(): array
    {
        return [
            'NAMESPACE' => 'App\\Actions',
            'CLASS_NAME' => $this->getClassName(),
        ];
    }
}
