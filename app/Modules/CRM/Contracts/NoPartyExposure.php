<?php

declare(strict_types=1);

namespace App\Modules\CRM\Contracts;

/**
 * The answer when nothing in the system takes cheques.
 *
 * Bound by CRM itself, so a credit check works in a deployment without the Cheques
 * module rather than failing to resolve. A null object rather than a nullable dependency:
 * every caller would otherwise carry the same `?? 0`, and the first one to forget it would
 * be the one that mattered.
 */
final class NoPartyExposure implements PartyExposure
{
    public function forPartyId(int $partyId): int
    {
        return 0;
    }

    /**
     * @param  list<int>  $partyIds
     * @return array<int, int>
     */
    public function forMany(array $partyIds): array
    {
        return [];
    }
}
