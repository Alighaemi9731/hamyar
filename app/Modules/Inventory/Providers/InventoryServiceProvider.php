<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Support\Modules\ModuleServiceProvider;

/**
 * Inventory module.
 *
 * Spec: docs/specs/inventory.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class InventoryServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        //
    }
}
