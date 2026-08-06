<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Base class every module's service provider extends.
 *
 * It wires the conventional module layout so an individual module provider only has
 * to declare bindings and event listeners — see ADR 0003 for why modules exist and
 * `docs/architecture.md#3-module-layout` for the folder contract.
 *
 * Conventions picked up automatically, when the corresponding path exists:
 *
 *   database/migrations/   loaded
 *   resources/views/       namespaced as `<module>::`
 *   lang/                  namespaced as `<module>::`
 *   routes/web.php         `web` middleware
 *   routes/api.php         `api` middleware, `/api` prefix
 *
 * Route files are NOT wrapped in tenant middleware here. Tenancy is decided per
 * route group inside each module (a module can expose both tenant screens and
 * public, unauthenticated pages — repair tracking and the storefront both do).
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->bootMigrations();
        $this->bootViews();
        $this->bootTranslations();
        $this->bootRoutes();
    }

    /**
     * Module name as used for view/translation namespaces, e.g. "Repairs".
     */
    public function moduleName(): string
    {
        // App\Modules\Repairs\Providers\RepairsServiceProvider → Repairs
        $namespace = (new ReflectionClass($this))->getNamespaceName();

        $segments = explode('\\', $namespace);

        return $segments[2] ?? class_basename($this);
    }

    public function modulePath(string $path = ''): string
    {
        $base = base_path('app/Modules/'.$this->moduleName());

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    protected function bootMigrations(): void
    {
        $path = $this->modulePath('database/migrations');

        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    protected function bootViews(): void
    {
        $path = $this->modulePath('resources/views');

        if (is_dir($path)) {
            $this->loadViewsFrom($path, $this->namespaceKey());
        }
    }

    protected function bootTranslations(): void
    {
        $path = $this->modulePath('lang');

        if (is_dir($path)) {
            $this->loadTranslationsFrom($path, $this->namespaceKey());
        }
    }

    protected function bootRoutes(): void
    {
        $web = $this->modulePath('routes/web.php');

        if (is_file($web)) {
            Route::middleware('web')->group($web);
        }

        $api = $this->modulePath('routes/api.php');

        if (is_file($api)) {
            Route::middleware('api')->prefix('api')->group($api);
        }
    }

    private function namespaceKey(): string
    {
        return Str::kebab($this->moduleName());
    }
}
