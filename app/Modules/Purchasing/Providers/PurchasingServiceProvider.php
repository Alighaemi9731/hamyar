<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Providers;

use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Support\Documents\DocumentReference;
use App\Support\Documents\DocumentRegistry;
use App\Support\Modules\ModuleServiceProvider;

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
        //
    }

    public function boot(): void
    {
        parent::boot();

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
    }
}
