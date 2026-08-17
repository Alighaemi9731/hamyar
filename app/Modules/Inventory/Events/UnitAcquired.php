<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A serialized device entered the shop's stock.
 *
 * Dispatched by every acquisition route — a purchase invoice being received, a trade-in
 * taken at the counter — so modules that care about *arrival* do not each have to know all
 * of them. HAMTA is the first consumer: a used device owes an ownership transfer whichever
 * door it came through, and before this event existed the two paths would have needed the
 * same listener wired twice, with the third one forgotten.
 *
 * Dispatched **after** the unit and its passport row are written, so a listener reading the
 * device's history sees a device with a history.
 */
final class UnitAcquired
{
    use Dispatchable;

    public function __construct(public readonly ProductUnit $unit) {}
}
