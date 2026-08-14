<?php

declare(strict_types=1);

namespace App\Modules\CRM\Contracts;

/**
 * What a party has at risk beyond their ledger balance.
 *
 * ## Why CRM declares this instead of calling the Cheques module
 *
 * The credit check needs a number only the Cheques module can compute, and CRM must not
 * know that module exists (golden rule 6, ADR 0003). So CRM declares the *question* and
 * whoever can answer it binds an implementation — the dependency points from Cheques to
 * CRM, which is the direction that keeps CRM usable in a deployment where cheques are not
 * enabled at all.
 *
 * ## Why the number is not in the ledger
 *
 * A received cheque settles the customer's debt when it is taken (`docs/specs/cheques.md`
 * §1), so their balance is genuinely zero — the books are right. But zero is a terrible
 * basis for a credit decision when the shop is holding 300,000,000 of their post-dated
 * paper, and recording that risk as a ledger balance would put a debt on their statement
 * that they would rightly dispute.
 *
 * Exposure is the shop's risk, not the customer's debt. Two different numbers, both true.
 */
interface PartyExposure
{
    /**
     * Face value still at risk for this party, in rial. Zero when nothing is.
     */
    public function forPartyId(int $partyId): int;

    /**
     * The same for many parties at once, keyed by party id.
     *
     * A customer picker showing twelve balances must not fire twelve aggregates per
     * keystroke — the same reason every list screen in this system has a batch method.
     *
     * @param  list<int>  $partyIds
     * @return array<int, int>
     */
    public function forMany(array $partyIds): array;
}
