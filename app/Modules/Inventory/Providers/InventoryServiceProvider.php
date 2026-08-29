<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Listeners\CreateDefaultLocation;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Policies\ProductUnitPolicy;
use App\Modules\Inventory\Services\BranchAccess;
use App\Modules\Inventory\Services\BranchContext;
use App\Modules\Platform\Events\TenantProvisioned;
use App\Support\Documents\DocumentReference;
use App\Support\Documents\DocumentRegistry;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\Window;
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
        /*
        | What this module meters. Declared here rather than in Platform so shipping a
        | metered action is a change in one module (golden rule 6), and registered with
        | `afterResolving` so provider discovery order — a directory listing — cannot
        | leave them out.
        */
        $this->app->afterResolving(MetricRegistry::class, static function (MetricRegistry $registry): void {
            $registry->register(
                new Metric('inventory.units', 'دستگاه ثبت‌شده', Window::Month, 'inventory', unitFa: 'دستگاه', position: 15, landing: true),
                new Metric('inventory.transfers', 'حوالهٔ انبار', Window::Month, 'inventory', unitFa: 'حواله', position: 16),
                new Metric('inventory.stock_counts', 'انبارگردانی', Window::Month, 'inventory', unitFa: 'انبارگردانی', position: 17),

                // A standing capacity, not a flow: what matters is how many branches
                // exist right now, and closing one gives the slot back. The default
                // branch every shop is provisioned with counts — otherwise the free
                // rung's "1" would silently mean two.
                new Metric(
                    'inventory.branches', 'شعبه', Window::Total, 'inventory',
                    unitFa: 'شعبه', position: 18,
                    measure: static fn (int $tenantId): int => Branch::query()
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', true)
                        ->count(),
                ),
            );
        });

        /*
        | Both branch services are singletons, and `BranchAccess` has to be.
        |
        | It memoises `branch_user` per user, and that memo is instance state. Without a
        | singleton binding every injection point gets its own copy, which is merely
        | wasteful — but `forget()` then becomes a **silent no-op**: it clears the cache on
        | a brand-new instance while the one the caller actually holds keeps answering from
        | the old list. `BranchController::assign()` calls it precisely so a staffing change
        | takes effect immediately, and that call did nothing until this binding existed.
        |
        | `BranchContext` follows because it holds the same `BranchAccess` and reads the
        | session; two copies disagreeing about the current branch within one request is the
        | kind of bug that only shows up as a report and a list screen filtering differently.
        */
        $this->app->singleton(BranchAccess::class);
        $this->app->singleton(BranchContext::class);
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
