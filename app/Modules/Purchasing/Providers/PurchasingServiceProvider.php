<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Providers;

use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Policies\PurchaseInvoicePolicy;
use App\Support\Documents\DocumentReference;
use App\Support\Documents\DocumentRegistry;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\Window;
use App\Support\Timeline\TimelineEntry;
use App\Support\Timeline\TimelineRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Purchasing module.
 *
 * Spec: docs/specs/purchasing.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class PurchasingServiceProvider extends ModuleServiceProvider
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
                new Metric('purchasing.invoices', 'فاکتور خرید', Window::Month, 'purchasing', unitFa: 'فاکتور', position: 30),
            );
        });

        //
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(PurchaseInvoice::class, PurchaseInvoicePolicy::class);

        // How a purchase invoice names itself when it appears on someone else's
        // screen — most importantly as the first line of an IMEI passport, which is
        // where "bought from whom" is answered. Inventory never learns this class
        // exists; it asks the shared registry.
        $this->app->make(DocumentRegistry::class)->register(
            PurchaseInvoice::class,
            static fn (array $ids): array => PurchaseInvoice::query()
                ->whereKey($ids)
                ->get(['id', 'number'])
                ->mapWithKeys(fn (PurchaseInvoice $invoice): array => [
                    $invoice->id => new DocumentReference('فاکتور خرید '.$invoice->number),
                ])
                ->all()
        );

        $this->contributeToPartyTimeline();
    }

    /**
     * What this shop has bought from a party, on that party's customer page.
     *
     * The same person routinely sells the shop a trade-in and buys a charger, so the
     * customer page has to show both sides — and CRM cannot import Purchasing to do it
     * (ADR 0003). Only *received* shipments appear: a draft is a shopping list someone
     * is still writing, not something that happened.
     */
    private function contributeToPartyTimeline(): void
    {
        $this->app->make(TimelineRegistry::class)->contribute(
            'Purchasing',
            static function (int $partyId, ?CarbonImmutable $from, ?CarbonImmutable $to): array {
                $entries = [];

                $invoices = PurchaseInvoice::query()
                    ->where('party_id', $partyId)
                    ->where('status', PurchaseInvoice::STATUS_RECEIVED)
                    ->when($from instanceof CarbonImmutable, fn ($query) => $query->where('received_at', '>=', $from))
                    ->when($to instanceof CarbonImmutable, fn ($query) => $query->where('received_at', '<=', $to))
                    ->orderByDesc('received_at')
                    ->limit(60)
                    ->get();

                foreach ($invoices as $invoice) {
                    $receivedAt = $invoice->received_at;

                    if (! $receivedAt instanceof CarbonImmutable) {
                        continue;
                    }

                    $entries[] = new TimelineEntry(
                        occurredAt: $receivedAt,
                        kind: 'purchase',
                        title: 'خرید از این طرف حساب',
                        description: 'فاکتور خرید '.$invoice->number,
                        // Negative from the party's side, matching the ledger's
                        // convention: buying from them makes the shop owe them.
                        amount: -$invoice->total,
                        url: route('purchasing.invoices.edit', $invoice, absolute: false),
                    );
                }

                return $entries;
            }
        );
    }
}
