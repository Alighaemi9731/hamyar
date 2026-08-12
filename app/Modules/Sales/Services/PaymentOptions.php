<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\Sales\Enums\PaymentMethod;

/**
 * What a payment box may offer.
 *
 * Extracted when Repairs' delivery screen needed the same two lists the till uses.
 * Duplicating them would have been two places to add a payment method, two ideas of
 * which accounts money may land in, and — the one that actually bites — two chances to
 * forget that `inventory` and `sales` are bookkeeping subjects rather than cash boxes.
 *
 * Lives in Sales because payment is a Sales concept; Repairs consumes it as a public
 * service, the same way it consumes `DraftInvoiceWriter` (ADR 0003).
 */
final class PaymentOptions
{
    /**
     * Where money can actually land: cash boxes, terminals and banks.
     *
     * `inventory` and `sales` are excluded deliberately. They are bookkeeping subjects,
     * not places a customer's money goes, and offering them at a till would let somebody
     * post a sale's cash straight into revenue twice.
     *
     * @return list<array{id: int, name: string, type: string, is_default: bool}>
     */
    public function accounts(): array
    {
        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('type', [Account::TYPE_CASH, Account::TYPE_BANK, Account::TYPE_POS_TERMINAL])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_default'])
            ->all();

        return array_values(array_map(fn (Account $account): array => [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type,
            'is_default' => $account->is_default,
        ], $accounts));
    }

    /**
     * Every method, with what the UI needs to know about each.
     *
     * @return list<array{value: string, label: string, needs_account: bool, needs_reference: bool}>
     */
    public function methods(): array
    {
        return array_map(fn (PaymentMethod $method): array => [
            'value' => $method->value,
            'label' => $method->labelFa(),
            'needs_account' => $method->needsAccount(),
            // The evidence the shop is asked for when a payment is disputed weeks later.
            'needs_reference' => in_array(
                $method,
                [PaymentMethod::PosTerminal, PaymentMethod::CardToCard, PaymentMethod::Cheque],
                true,
            ),
        ], PaymentMethod::cases());
    }
}
