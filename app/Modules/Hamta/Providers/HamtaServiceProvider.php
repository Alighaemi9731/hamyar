<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Providers;

use App\Modules\Hamta\Listeners\FlagAcquiredUsedDevice;
use App\Modules\Hamta\Listeners\FlagSoldUsedDevice;
use App\Modules\Inventory\Events\UnitAcquired;
use App\Modules\Sales\Events\InvoiceFinalised;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Hamta module.
 *
 * Spec: docs/specs/hamta.md
 *
 * ## Record-keeping and guidance. There is no integration and there will not be one.
 *
 * همتا has **no public API**. Everything in this module is a status a member of staff typed
 * and a checklist they worked through, and every screen says so in those words. The spec is
 * blunt about why: promising customers a direct integration would be a lie, and the first
 * time a transfer silently failed the shop would be the one blamed.
 *
 * ## Two listeners, and before them these columns had no writer
 *
 * `product_units.hamta_status` shipped in Phase 3 and nothing set it for seven phases —
 * every device in every shop read `not_required`, used ones included. The warnings that
 * depend on it had nothing to depend on. See `docs/testing.md`, *"a feature with enforcement
 * but no write path is invisible"*; this module is that pattern's second confirmed instance.
 */
final class HamtaServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        /*
        | In on one event, out on the other.
        |
        | `UnitAcquired` covers every door a device comes in through — a purchase invoice
        | being received, a trade-in at the counter — so a third acquisition path added
        | later is covered without anybody remembering this module exists. That is the
        | whole reason the event was introduced rather than wiring two listeners here.
        */
        Event::listen(UnitAcquired::class, FlagAcquiredUsedDevice::class);
        Event::listen(InvoiceFinalised::class, FlagSoldUsedDevice::class);
    }
}
