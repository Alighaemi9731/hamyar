<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Purchasing\Models\PurchaseInvoice;

/**
 * Authorization for shipments.
 *
 * `create` covers editing a draft too: a draft is not a document yet, it is a shopping
 * list someone is still writing. `receive` is the same permission on purpose —
 * receiving is what turns the list into stock and a debt, and the person building the
 * shipment is the person unpacking the box.
 *
 * Purchase costs are visible on every screen here, so `purchasing.view` implies seeing
 * what the shop paid. That is why the Salesperson role does not have it.
 */
final class PurchaseInvoicePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('purchasing.view');
    }

    public function view(User $actor, PurchaseInvoice $invoice): bool
    {
        return $actor->can('purchasing.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('purchasing.create');
    }

    /**
     * A policy answers *who*, never *when*.
     *
     * There is no `isDraft()` clause here, deliberately. The Owner `Gate::before`
     * override (see IdentityServiceProvider) returns true for every ability before any
     * policy method runs, so a document-state check written here is silently dead for
     * exactly the person most likely to click the button. Document state is enforced by
     * `PurchaseInvoiceController::guardDraft()` and, underneath it, by the services —
     * both of which the override cannot skip.
     */
    public function update(User $actor, PurchaseInvoice $invoice): bool
    {
        return $actor->can('purchasing.create');
    }

    public function receive(User $actor, PurchaseInvoice $invoice): bool
    {
        return $actor->can('purchasing.create');
    }

    public function return(User $actor, PurchaseInvoice $invoice): bool
    {
        return $actor->can('purchasing.return');
    }
}
