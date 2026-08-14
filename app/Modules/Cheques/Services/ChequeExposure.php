<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Services;

use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\CRM\Contracts\PartyExposure;
use App\Modules\CRM\Models\Party;

/**
 * What a customer still has at risk, beyond what their balance says.
 *
 * ## The number that makes settle-on-receipt safe
 *
 * A received cheque settles the customer's debt the moment it is taken, so a customer who
 * has paid entirely in post-dated cheques has a party balance of zero. That is the correct
 * accounting and it is a terrible basis for a credit decision: under a balance-only check
 * they would have unlimited further credit — which is precisely the customer a credit
 * limit exists to stop.
 *
 * Exposure is the answer, and it lives here rather than in `ledger_entries`, because it is
 * not a debt. The customer owes nothing; the shop is *at risk* of them owing again.
 * Recording that as a ledger balance would make the party statement wrong — showing a debt
 * a customer would rightly dispute.
 *
 * ## Why endorsed cheques still count
 *
 * Passing a cheque to a supplier does not discharge recourse. If it bounces at the
 * endorsee, the shop is liable to them and the drawer is still the drawer. A shop that
 * treats endorsed paper as settled has quietly given the customer more credit than it
 * knows.
 */
final class ChequeExposure implements PartyExposure
{
    /**
     * Face value of this party's received cheques that could still come back, in rial.
     */
    public function forParty(Party $party): int
    {
        return $this->forPartyId($party->id);
    }

    public function forPartyId(int $partyId): int
    {
        $cheques = Cheque::query()
            ->where('direction', ChequeDirection::Received->value)
            ->where('party_id', $partyId)
            ->whereIn('status', ChequeStatus::outstandingForExposure())
            ->get(['amount', 'recovered_amount']);

        $total = 0;

        foreach ($cheques as $cheque) {
            // Net of anything a bank actually paid on a partial settlement: that money is
            // in the shop's account and is not at risk any more.
            $total += $cheque->outstanding();
        }

        return $total;
    }

    /**
     * Exposure for many parties at once, keyed by party id.
     *
     * The list-screen companion, for the same reason every other module has one: a
     * customer picker showing twelve balances must not fire twelve aggregates per
     * keystroke.
     *
     * @param  list<int>  $partyIds
     * @return array<int, int>
     */
    public function forMany(array $partyIds): array
    {
        if ($partyIds === []) {
            return [];
        }

        $rows = Cheque::query()
            ->where('direction', ChequeDirection::Received->value)
            ->whereIn('party_id', $partyIds)
            ->whereIn('status', ChequeStatus::outstandingForExposure())
            ->groupBy('party_id')
            ->selectRaw('party_id, coalesce(sum(amount - recovered_amount), 0) as exposure')
            ->toBase()
            ->get();

        $exposure = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $id = $values['party_id'] ?? null;
            $amount = $values['exposure'] ?? 0;

            if (is_numeric($id)) {
                $exposure[(int) $id] = is_numeric($amount) ? (int) $amount : 0;
            }
        }

        return $exposure;
    }
}
