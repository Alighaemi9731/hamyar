<?php

declare(strict_types=1);

namespace App\Modules\Installments\Events;

use App\Modules\Installments\Models\InstallmentCollection;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer paid something against their plan.
 *
 * Carries the collection rather than just the row, because the interesting facts are on
 * it: how much, into which account, and how much of it was a late fee. Phase 8 texts a
 * receipt off this, and the CRM timeline wants the figure rather than a lookup.
 *
 * Fired after commit — nothing thanks a customer for a payment that rolled back.
 */
final class InstallmentCollected
{
    use Dispatchable;

    public function __construct(public readonly InstallmentCollection $collection) {}
}
