<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Listeners;

use App\Modules\Hamta\Services\HamtaRegistry;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Sales\Events\InvoiceFinalised;

/**
 * A used handset left the shop, so somebody owes the registry a transfer.
 *
 * ## Selling is the transfer that matters most
 *
 * The one on the way in protects the shop; this one protects the **customer**, who
 * otherwise finds their phone restricted months later with no idea why. Spec: a used sale
 * can complete with the transfer outstanding — the shop's workflow cannot be held hostage
 * to a third party — but it is recorded, and the pending list is a screen someone clears.
 *
 * ## Synchronous, and it must not throw
 *
 * `InvoiceFinalised` fires after the sale commits. A flag write that failed here would be a
 * missing warning, which is bad; an exception here would be an exception *after* a completed
 * sale, which is worse — the till would show an error for a transaction that already
 * happened. So the work is a status write on rows this listener has just read, with nothing
 * that can fail beyond the database being gone.
 */
final class FlagSoldUsedDevice
{
    public function __construct(private readonly HamtaRegistry $registry) {}

    public function handle(InvoiceFinalised $event): void
    {
        $unitIds = $event->invoice->items()
            ->whereNotNull('product_unit_id')
            ->pluck('product_unit_id')
            ->all();

        if ($unitIds === []) {
            return;
        }

        $units = ProductUnit::query()->whereKey($unitIds)->get();

        foreach ($units as $unit) {
            // `markPending` is the one that knows which conditions owe a transfer, so a
            // new phone on the same invoice is skipped without this listener deciding.
            $this->registry->markPending($unit, 'sold');
        }
    }
}
