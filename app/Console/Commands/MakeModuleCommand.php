<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Scaffolds a module under app/Modules/<Name> (ADR 0003).
 *
 * The layout is fixed rather than configurable on purpose: arch tests assert against
 * it, and eighteen modules that look identical are eighteen modules any contributor
 * can navigate without reading a README.
 */
final class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
                            {name : Studly-case module name, e.g. Repairs}
                            {--force : Overwrite the provider if the module already exists}';

    protected $description = 'Create a module skeleton under app/Modules';

    /**
     * Directories every module gets. Empty ones keep a .gitkeep so the shape of a
     * fresh module survives a git clone.
     *
     * @var list<string>
     */
    private const DIRECTORIES = [
        'Providers',
        'Http/Controllers',
        'Http/Requests',
        'Http/Middleware',
        'Http/Resources',
        'Models',
        'Services',
        'Actions',
        'Events',
        'Listeners',
        'Policies',
        'Enums',
        'database/migrations',
        'database/factories',
        'database/seeders',
        'resources/views',
        'routes',
        'tests/Feature',
        'tests/Unit',
    ];

    public function handle(Filesystem $files): int
    {
        $name = Str::studly((string) $this->argument('name'));

        if ($name === '' || preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            $this->components->error("Module name [{$name}] must be StudlyCase and alphanumeric.");

            return self::FAILURE;
        }

        $slug = Str::kebab($name);
        $path = base_path("app/Modules/{$name}");
        $providerPath = "{$path}/Providers/{$name}ServiceProvider.php";

        if ($files->exists($providerPath) && ! $this->option('force')) {
            $this->components->warn("Module [{$name}] already exists. Use --force to overwrite its provider.");

            return self::SUCCESS;
        }

        foreach (self::DIRECTORIES as $directory) {
            $files->ensureDirectoryExists("{$path}/{$directory}");

            $keep = "{$path}/{$directory}/.gitkeep";

            if ($files->isEmptyDirectory("{$path}/{$directory}")) {
                $files->put($keep, '');
            }
        }

        $files->put($providerPath, $this->render($files, 'provider.stub', $name, $slug));
        $files->put("{$path}/routes/web.php", $this->render($files, 'routes-web.stub', $name, $slug));

        // routes/ now has a real file; the placeholder would only be noise.
        $files->delete("{$path}/routes/.gitkeep");
        $files->delete("{$path}/Providers/.gitkeep");

        $this->components->info("Module [{$name}] created at app/Modules/{$name}.");
        $this->components->bulletList([
            'Provider auto-registers via App\\Providers\\ModuleServiceProvider — no bootstrap edit needed.',
            "Write the spec first: docs/specs/{$slug}.md",
            'Tenant tables need tenant_id + composite index + enableRls() in the same migration.',
        ]);

        return self::SUCCESS;
    }

    private function render(Filesystem $files, string $stub, string $module, string $slug): string
    {
        $contents = $files->get(base_path("stubs/module/{$stub}"));

        return str_replace(
            ['{{ module }}', '{{ slug }}'],
            [$module, $slug],
            $contents
        );
    }
}
