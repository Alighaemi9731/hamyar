<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * Authorization for the counter.
 *
 * `viewProfit` is separate from `view` because a salesperson has to be able to reprint
 * a customer's invoice without seeing what the shop made on it — Gate 1's boundary, and
 * the most commercially sensitive number in the building.
 *
 * `void` is its own permission too: cancelling an issued tax invoice is not the same
 * act as writing one, and shops keep it to an owner.
 */
final class SalesInvoicePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('sales.view');
    }

    public function view(User $actor, SalesInvoice $invoice): bool
    {
        return $actor->can('sales.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('sales.create');
    }

    /**
     * Editing a basket. Only ever a draft — an issued invoice is stock, money and a
     * numbered tax document, and the controller guards that state separately so the
     * Owner `Gate::before` override cannot skip it.
     */
    public function update(User $actor, SalesInvoice $invoice): bool
    {
        return $actor->can('sales.create');
    }

    public function void(User $actor, SalesInvoice $invoice): bool
    {
        return $actor->can('sales.void');
    }

    public function return(User $actor, SalesInvoice $invoice): bool
    {
        return $actor->can('sales.return');
    }

    public function viewProfit(User $actor): bool
    {
        return $actor->can('sales.view_profit');
    }
}
