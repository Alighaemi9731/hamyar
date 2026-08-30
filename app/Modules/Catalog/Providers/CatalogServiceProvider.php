<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Listeners\SeedPriceLevels;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Policies\CategoryPolicy;
use App\Modules\Catalog\Policies\ProductPolicy;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\Platform\Events\TenantProvisioned;
use App\Support\Audit\AuditSubjects;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\Window;
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
        | What this module meters.
        |
        | Declared here rather than in Platform so shipping a metered action is a change
        | in one module (golden rule 6): the pricing page, the Filament limits editor, the
        | usage meters and the analytics all iterate `MetricRegistry` and pick this up
        | without Platform knowing the key exists.
        |
        | `afterResolving` rather than resolving the registry now: provider discovery
        | order is a directory listing, and a registry built before this provider ran
        | would silently be missing these — the `bindIf` lesson, applied to a registry.
        */
        $this->app->afterResolving(MetricRegistry::class, static function (MetricRegistry $registry): void {
            $registry->register(
                new Metric('catalog.products', 'کالای جدید', Window::Month, 'catalog', unitFa: 'کالا', position: 20, landing: true),
            );
        });

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

        // What the audit log is allowed to be about, from the module that owns the
        // models — so the filter dropdown cannot drift from what is actually audited.
        // Positions are set rather than left to registration order, which is module
        // discovery order and therefore a directory listing.
        //
        // The third argument of each is the namer: how one record of that kind titles
        // its own history page. Registered here beside the subject rather than in the
        // DocumentRegistry, so a module declares an audited thing exactly once.
        $subjects = $this->app->make(AuditSubjects::class);

        $subjects->register(
            'product', Product::class, 'کالا', 10,
            static fn (int $id): ?string => Product::query()->find($id)?->name,
            // A product's history includes its variants'. Price changes are logged
            // against the variant, and «کی این قیمت را عوض کرد؟» is asked while
            // looking at the product — without this the link built to answer it opens
            // a page with every kind of change on it except that one.
            static fn (int $id): array => [
                ProductVariant::class => ProductVariant::query()
                    ->where('product_id', $id)
                    ->pluck('id')
                    ->all(),
            ],
        );

        $subjects->register(
            'variant', ProductVariant::class, 'تنوع کالا', 20,
            static fn (int $id): ?string => ProductVariant::query()->with('product:id,name')->find($id)?->displayName(),
        );

        $subjects->register(
            'price-level', PriceLevel::class, 'سطح قیمت', 30,
            static fn (int $id): ?string => PriceLevel::query()->find($id)?->name_fa,
        );
    }
}
