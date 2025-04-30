<?php

namespace Frontier\Repositories\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Pluralizer;

use function Laravel\Prompts\alert;
use function Laravel\Prompts\info;

abstract class AbstractMake extends Command
{
    public function handle(): int
    {
        $this->make();

        return 0;
    }

    protected function make(): void
    {
        $path = $this->getSourceFilePath();

        $this->makeDirectory(dirname($path));

        $contents = $this->getSourceFile();

        if (! File::exists($path)) {
            File::put($path, $contents);
            info(sprintf('%s created', $path));
        } else {
            alert(sprintf('%s already exists', $path));
        }
    }

    protected function getSourceFile(): string|array|false
    {
        return $this->getStubContents($this->getStubPath(), $this->getStubVariables());
    }

    protected function getStubContents($stub, $stubVariables = []): array|false|string
    {
        $contents = file_get_contents($stub);

        foreach ($stubVariables as $search => $replace) {
            $contents = str_replace('$'.$search.'$', $replace, $contents);
        }

        return $contents;
    }

    protected function getClassName(): string
    {
        return ucwords($this->argument('name', ''));
    }

    protected function getSingularClassName(): string
    {
        return Pluralizer::singular($this->getClassName());
    }

    protected function makeDirectory($path)
    {
        if (! File::isDirectory($path)) {
            File::makeDirectory($path, 0777, true, true);
        }

        return $path;
    }

    public function getSourceFilePath(): string
    {
        return '';
    }

    public function getStubPath(): string
    {
        return '';
    }

    public function getStubVariables(): array
    {
        return [
            'NAMESPACE' => 'App',
            'CLASS_NAME' => $this->getClassName(),
        ];
    }
}
