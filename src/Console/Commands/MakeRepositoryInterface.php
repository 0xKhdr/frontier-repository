<?php

declare(strict_types=1);

namespace Frontier\Repositories\Console\Commands;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use InterNACHI\Modular\Support\Facades\Modules;

use function Laravel\Prompts\select;

/**
 * Artisan command to generate a new repository interface.
 */
class MakeRepositoryInterface extends GeneratorCommand
{
    protected $signature = 'frontier:repository-interface {name} {--module= : The module to create the interface in}';

    protected $description = 'Create a new repository interface';

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

        // Check if --module was used but internachi/modular is not installed
        if (($module !== null || $this->moduleOptionWasPassedWithoutValue()) && ! $this->isModularInstalled()) {
            $this->components->error('The --module option requires the internachi/modular package. Install it with: composer require internachi/modular');

            return;
        }

        // --module passed without value = show interactive selection
        if ($module === null && $this->moduleOptionWasPassedWithoutValue()) {
            $modules = $this->getAvailableModules();

            if ($modules === []) {
                $this->components->warn('No modules found in '.config('app-modules.modules_directory', 'app-modules'));

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

    /**
     * Check if internachi/modular package is installed.
     */
    protected function isModularInstalled(): bool
    {
        return class_exists(\InterNACHI\Modular\Support\ModuleRegistry::class);
    }

    /**
     * Check if --module was passed without a value.
     */
    protected function moduleOptionWasPassedWithoutValue(): bool
    {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if ($arg === '--module' || str_starts_with((string) $arg, '--module=')) {
                return $arg === '--module';
            }
        }

        return false;
    }

    /**
     * Get available modules using internachi/modular's Modules facade.
     */
    protected function getAvailableModules(): array
    {
        try {
            return Modules::modules()
                ->map(fn ($module) => $module->name)
                ->sort()
                ->values()
                ->toArray();
        } catch (\Throwable) {
            // Fallback: scan directory
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

            return base_path("{$directory}/{$module}/src/Repositories/Contracts/{$this->getClassName()}.php");
        }

        return App::path('Repositories/Contracts/'.$this->getClassName()).'.php';
    }

    public function getStubPath(): string
    {
        return __DIR__.'/../../../stubs/repository-interface.stub';
    }

    public function getStubVariables(): array
    {
        $module = $this->getModule();

        if ($module) {
            $namespace = config('app-modules.modules_namespace', 'Modules');
            $moduleNamespace = $namespace.'\\'.Str::studly($module).'\\Repositories\\Contracts';

            return [
                'NAMESPACE' => $moduleNamespace,
                'CLASS_NAME' => $this->getClassName(),
            ];
        }

        return [
            'NAMESPACE' => 'App\\Repositories\\Contracts',
            'CLASS_NAME' => $this->getClassName(),
        ];
    }
}
