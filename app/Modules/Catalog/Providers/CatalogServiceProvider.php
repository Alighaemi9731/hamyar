<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Listeners\SeedPriceLevels;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Policies\CategoryPolicy;
use App\Modules\Catalog\Policies\ProductPolicy;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\Platform\Events\TenantProvisioned;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

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
        /*
        | Shared, so that `forget()` means something — `bin/check-forgettable-singletons`
        | enforces the pairing.
        |
        | The prices a screen resolves are the same prices the next screen resolves in the
        | same request, and a per-injection instance threw that away. The cache key had to
        | gain the tenant first: a shared memo keyed only by variant id becomes a
        | cross-tenant leak the moment two shops are served in one process, which happens in
        | a queued job, in a test's `runFor()`, and in the storefront's token resolution.
        */
        $this->app->singleton(PriceResolver::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);

        Event::listen(TenantProvisioned::class, SeedPriceLevels::class);
    }
}
