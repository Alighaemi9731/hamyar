<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Discovers and registers every module's service provider.
 *
 * Adding a module means creating a folder — never editing `bootstrap/providers.php`.
 * Discovery is a single glob over `app/Modules/*​/Providers/*ServiceProvider.php`
 * (18 directories), which is cheap enough not to warrant a cached manifest; if that
 * ever changes, cache the resolved list in `bootstrap/cache/modules.php`.
 *
 * Registration order is alphabetical and must not be relied upon: modules talk to
 * each other through domain events and bound interfaces (ADR 0003), both of which
 * resolve lazily at boot-time or later.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ($this->discover() as $provider) {
            $this->app->register($provider);
        }
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    public function discover(): array
    {
        $pattern = base_path('app/Modules/*/Providers/*ServiceProvider.php');

        $paths = glob($pattern);

        if ($paths === false) {
            return [];
        }

        sort($paths);

        $providers = [];

        foreach ($paths as $path) {
            $class = $this->classFromPath($path);

            if ($class !== null && class_exists($class) && is_subclass_of($class, ServiceProvider::class)) {
                $providers[] = $class;
            }
        }

        return $providers;
    }

    /**
     * app/Modules/Repairs/Providers/RepairsServiceProvider.php
     *   → App\Modules\Repairs\Providers\RepairsServiceProvider
     *
     * @return class-string|null
     */
    private function classFromPath(string $path): ?string
    {
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        $relative = str_replace('\\', '/', $relative);

        if (! str_starts_with($relative, 'app/') || ! str_ends_with($relative, '.php')) {
            return null;
        }

        $withoutExtension = substr($relative, 4, -4);

        /** @var class-string $class */
        $class = 'App\\'.str_replace('/', '\\', $withoutExtension);

        return $class;
    }
}
