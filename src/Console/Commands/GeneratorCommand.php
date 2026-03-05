<?php

declare(strict_types=1);

namespace Frontier\Repositories\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Pluralizer;
use Illuminate\Support\Str;
use InterNACHI\Modular\Support\Facades\Modules;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;

/**
 * Base generator command for scaffolding repository classes.
 *
 * Concrete commands implement getSubNamespace() and getStubPath(). Everything
 * else — module resolution, file creation, namespace derivation — lives here.
 */
abstract class GeneratorCommand extends Command
{
    protected ?string $selectedModule = null;

    /**
     * The sub-namespace that uniquely identifies the generated file's location.
     *
     * Use PHP namespace separator (backslash), e.g. 'Repositories\\Cache'.
     * This is used to derive both the filesystem path and the PHP namespace.
     */
    abstract protected function getSubNamespace(): string;

    abstract public function getStubPath(): string;

    // -------------------------------------------------------------------------
    // Command entry point
    // -------------------------------------------------------------------------

    public function handle(): int
    {
        $this->resolveModule();
        $this->make();

        return 0;
    }

    // -------------------------------------------------------------------------
    // Module resolution (shared across all concrete commands)
    // -------------------------------------------------------------------------

    /**
     * Resolve the module — show interactive selection if --module passed without value.
     */
    protected function resolveModule(): void
    {
        $module = $this->option('module');

        if (($module !== null || $this->moduleOptionWasPassedWithoutValue()) && ! $this->isModularInstalled()) {
            $this->components->error('The --module option requires the internachi/modular package. Install it with: composer require internachi/modular');

            return;
        }

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

    protected function isModularInstalled(): bool
    {
        return class_exists(\InterNACHI\Modular\Support\ModuleRegistry::class);
    }

    /**
     * Detect whether --module was passed without a value.
     *
     * Uses Symfony's InputInterface::hasParameterOption() rather than reading
     * $_SERVER['argv'] directly, which makes the check testable and
     * framework-consistent. hasParameterOption('--module') returns true only
     * for the bare flag; it returns false when --module=value is supplied.
     */
    protected function moduleOptionWasPassedWithoutValue(): bool
    {
        return $this->input->hasParameterOption('--module') && $this->option('module') === null;
    }

    /**
     * Get available modules via internachi/modular, falling back to directory scan.
     *
     * @return array<int, string>
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

    // -------------------------------------------------------------------------
    // File generation
    // -------------------------------------------------------------------------

    protected function make(): void
    {
        $path = $this->getSourceFilePath();

        $this->makeDirectory(dirname($path));

        $contents = $this->getSourceFile();

        if (! File::exists($path)) {
            File::put($path, $contents);
            info(sprintf('%s created', $path));
        } else {
            info(sprintf('%s already exists', $path));
        }
    }

    protected function getSourceFile(): string|array|false
    {
        return $this->getStubContents($this->getStubPath(), $this->getStubVariables());
    }

    protected function getStubContents(string $stub, array $stubVariables = []): array|false|string
    {
        $contents = file_get_contents($stub);

        foreach ($stubVariables as $search => $replace) {
            $contents = str_replace('$'.$search.'$', $replace, $contents);
        }

        return $contents;
    }

    // -------------------------------------------------------------------------
    // Path and namespace derivation
    // -------------------------------------------------------------------------

    /**
     * Derive the output file path from the sub-namespace and optional module.
     */
    public function getSourceFilePath(): string
    {
        $name = $this->getClassName();
        $subdir = $this->getSubdirectory();

        if ($module = $this->getModule()) {
            $base = config('app-modules.modules_directory', 'app-modules');

            return base_path("{$base}/{$module}/src/{$subdir}/{$name}.php");
        }

        return App::path("{$subdir}/{$name}.php");
    }

    /**
     * Build the stub variable map: NAMESPACE + CLASS_NAME.
     *
     * @return array<string, string>
     */
    public function getStubVariables(): array
    {
        $name = $this->getClassName();
        $subNs = $this->getSubNamespace();

        if ($module = $this->getModule()) {
            $rootNs = config('app-modules.modules_namespace', 'Modules');

            return [
                'NAMESPACE' => $rootNs.'\\'.Str::studly($module).'\\'.$subNs,
                'CLASS_NAME' => $name,
            ];
        }

        return [
            'NAMESPACE' => 'App\\'.$subNs,
            'CLASS_NAME' => $name,
        ];
    }

    /**
     * Convert the sub-namespace (backslash-separated) to a filesystem path.
     *
     * e.g. 'Repositories\\Cache' → 'Repositories/Cache'
     */
    protected function getSubdirectory(): string
    {
        return str_replace('\\', '/', $this->getSubNamespace());
    }

    protected function getClassName(): string
    {
        return ucwords($this->argument('name'));
    }

    protected function getSingularClassName(): string
    {
        return Pluralizer::singular($this->getClassName());
    }

    protected function makeDirectory(string $path): string
    {
        if (! File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true, true);
        }

        return $path;
    }
}
