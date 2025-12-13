<?php

declare(strict_types=1);

namespace Frontier\Repositories\Console\Commands;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use InterNACHI\Modular\Support\Facades\Modules;

use function Laravel\Prompts\select;

/**
 * Artisan command to generate a new cacheable repository class.
 */
class MakeRepositoryCache extends GeneratorCommand
{
    protected $signature = 'frontier:repository-cache {name} {--module= : The module to create the repository in}';

    protected $description = 'Create a new cacheable repository class';

    protected ?string $selectedModule = null;

    public function handle(): int
    {
        $this->resolveModule();

        return parent::handle();
    }

    /**
     * Resolve the module - show interactive selection if --module passed without value.
     */
    protected function resolveModule(): void
    {
        $module = $this->option('module');

        if (($module !== null || $this->moduleOptionWasPassedWithoutValue()) && ! $this->isModularInstalled()) {
            $this->components->error('The --module option requires the internachi/modular package.');

            return;
        }

        if ($module === null && $this->moduleOptionWasPassedWithoutValue()) {
            $modules = $this->getAvailableModules();

            if ($modules === []) {
                $this->components->warn('No modules found.');

                return;
            }

            $this->selectedModule = select(
                label: 'Select a module',
                options: $modules,
                scroll: 10
            );
        } elseif ($module) {
            $this->selectedModule = $module;
        }
    }

    protected function isModularInstalled(): bool
    {
        return class_exists(\InterNACHI\Modular\Support\ModuleRegistry::class);
    }

    protected function moduleOptionWasPassedWithoutValue(): bool
    {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if ($arg === '--module' || str_starts_with((string) $arg, '--module=')) {
                return $arg === '--module';
            }
        }

        return false;
    }

    protected function getAvailableModules(): array
    {
        try {
            return Modules::modules()
                ->map(fn ($module) => $module->name)
                ->sort()
                ->values()
                ->toArray();
        } catch (\Throwable) {
            $directory = base_path(config('app-modules.modules_directory', 'app-modules'));

            if (! is_dir($directory)) {
                return [];
            }

            return collect(scandir($directory))
                ->filter(fn ($dir): bool => $dir !== '.' && $dir !== '..' && is_dir($directory.'/'.$dir))
                ->sort()
                ->values()
                ->toArray();
        }
    }

    protected function getModule(): ?string
    {
        return $this->selectedModule;
    }

    public function getSourceFilePath(): string
    {
        $module = $this->getModule();

        if ($module) {
            $directory = config('app-modules.modules_directory', 'app-modules');

            return base_path("{$directory}/{$module}/src/Repositories/Cache/{$this->getClassName()}.php");
        }

        return App::path('Repositories/Cache/'.$this->getClassName()).'.php';
    }

    public function getStubPath(): string
    {
        return __DIR__.'/../../../stubs/repository-cache.stub';
    }

    public function getStubVariables(): array
    {
        $module = $this->getModule();
        $className = $this->getClassName();
        $repositoryName = str_replace('Cached', '', $className);

        if ($module) {
            $namespace = config('app-modules.modules_namespace', 'Modules');
            $moduleNamespace = $namespace.'\\'.Str::studly($module).'\\Repositories\\Cache';

            return [
                'NAMESPACE' => $moduleNamespace,
                'CLASS_NAME' => $className,
                'REPOSITORY_NAME' => $repositoryName,
            ];
        }

        return [
            'NAMESPACE' => 'App\\Repositories\\Cache',
            'CLASS_NAME' => $className,
            'REPOSITORY_NAME' => $repositoryName,
        ];
    }
}
