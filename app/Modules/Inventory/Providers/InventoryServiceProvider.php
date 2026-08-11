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
use App\Support\Timeline\TimelineEntry;
use App\Support\Timeline\TimelineRegistry;
use Carbon\CarbonImmutable;
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

        $this->contributeToPartyTimeline();

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

    /**
     * Devices this shop acquired from a party, on that party's customer page.
     *
     * This is the trade-in side of a customer's history, and the one line a shop most
     * often goes looking for: "which phone did we buy off him, and when". Linked
     * straight to the IMEI passport, because that is the next question.
     */
    private function contributeToPartyTimeline(): void
    {
        $this->app->make(TimelineRegistry::class)->contribute(
            'Inventory',
            static function (int $partyId, ?CarbonImmutable $from, ?CarbonImmutable $to): array {
                $entries = [];

                $units = ProductUnit::query()
                    ->with('variant.product:id,name')
                    ->where('acquired_from_party_id', $partyId)
                    ->whereNotNull('acquired_at')
                    ->when($from instanceof CarbonImmutable, fn ($query) => $query->where('acquired_at', '>=', $from))
                    ->when($to instanceof CarbonImmutable, fn ($query) => $query->where('acquired_at', '<=', $to))
                    ->orderByDesc('acquired_at')
                    ->limit(60)
                    ->get();

                foreach ($units as $unit) {
                    $acquiredAt = $unit->acquired_at;

                    if (! $acquiredAt instanceof CarbonImmutable) {
                        continue;
                    }

                    $entries[] = new TimelineEntry(
                        occurredAt: $acquiredAt,
                        kind: 'device',
                        title: 'دستگاه از این طرف حساب خریداری شد',
                        description: $unit->variant->product->name.' · '.($unit->imei1 ?? $unit->serial ?? ''),
                        url: route('inventory.units.show', $unit, absolute: false),
                    );
                }

                return $entries;
            }
        );
    }
}
