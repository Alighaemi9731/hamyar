<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Listeners\CreateDefaultLocation;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Policies\ProductUnitPolicy;
use App\Modules\Platform\Events\TenantProvisioned;
use App\Support\Documents\DocumentReference;
use App\Support\Documents\DocumentRegistry;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

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

    public function boot(): void
    {
        parent::boot();

        Gate::policy(ProductUnit::class, ProductUnitPolicy::class);

        // A serialized transfer moves the unit and deliberately writes no stock
        // movement, so the transfer document is the only trace of the journey — which
        // makes naming it on the passport the whole point.
        $this->app->make(DocumentRegistry::class)->register(
            StockTransfer::class,
            static fn (array $ids): array => StockTransfer::query()
                ->whereKey($ids)
                ->get(['id', 'number'])
                ->mapWithKeys(fn (StockTransfer $transfer): array => [
                    $transfer->id => new DocumentReference('حواله '.$transfer->number),
                ])
                ->all()
        );

        // Inventory listens for a new shop and creates its own starting data. Platform
        // dispatches the event and knows nothing about branches (golden rule 6).
        Event::listen(TenantProvisioned::class, CreateDefaultLocation::class);
    }
}
