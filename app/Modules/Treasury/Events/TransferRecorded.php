<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Events;

use App\Modules\Treasury\Models\AccountTransfer;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money moved between two of the shop's own accounts.
 *
 * Fired after commit, so nothing announces a transfer that rolled back. Phase 8 hangs an
 * SMS off large movements — the owner wants to know when 200,000,000 leaves the till,
 * whoever pressed the button — and the daily close listens so a banked figure appears
 * without a page refresh.
 */
final class TransferRecorded
{
    use Dispatchable;

    public function __construct(public readonly AccountTransfer $transfer) {}
}
