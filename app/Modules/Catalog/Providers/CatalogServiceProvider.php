<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Support\Modules\ModuleServiceProvider;

/**
 * Catalog module.
 *
 * Spec: docs/specs/catalog.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class CatalogServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        //
    }
}
