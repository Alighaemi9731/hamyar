<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Events;

use App\Modules\Cheques\Models\Cheque;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A cheque was dishonoured.
 *
 * The most serious event in the module in either direction: a received cheque bouncing
 * means a customer who appeared to have paid has not, and an issued one bouncing is the
 * shop's own credit failing in public. Phase 8 hangs an SMS and an owner alert off this.
 *
 * Fired after commit, so nothing announces a bounce that rolled back.
 */
final class ChequeBounced
{
    use Dispatchable;

    public function __construct(public readonly Cheque $cheque) {}
}
