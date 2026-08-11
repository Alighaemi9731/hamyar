<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\LoyaltyEntry;
use App\Modules\CRM\Models\LoyaltyRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Loyalty points, as a ledger.
 *
 * Golden rule 3 applied to points: a balance is `SUM(points)` and never a stored
 * column. The reason is the same as for money and stock, and sharper here — a customer
 * who is told their points are gone will ask why, and a shop that cannot show the line
 * that took them has a scheme nobody believes in.
 *
 * Redemption refuses to go negative. Points are not credit; there is nothing to collect
 * from someone who spends points they do not have.
 */
final class LoyaltyService
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function balanceFor(int $partyId): int
    {
        /** @var int|numeric-string|null $sum */
        $sum = LoyaltyEntry::query()->where('party_id', $partyId)->sum('points');

        return (int) ($sum ?? 0);
    }

    /**
     * The rule in force, if the shop has one.
     *
     * @return array{name: string, rial_per_point: int, expires_after_months: int|null}|null
     */
    public function activeRuleSummary(): ?array
    {
        $rule = $this->activeRule();

        if (! $rule instanceof LoyaltyRule) {
            return null;
        }

        return [
            'name' => $rule->name,
            'rial_per_point' => $rule->rial_per_point,
            'expires_after_months' => $rule->expires_after_months,
        ];
    }

    public function activeRule(): ?LoyaltyRule
    {
        return LoyaltyRule::query()->where('is_active', true)->first();
    }

    /**
     * Award the points a spend earns under the active rule.
     *
     * Returns null when the shop runs no scheme, or when the spend is too small to earn
     * anything — both are "nothing happened", and writing a zero-point row to say so
     * would pad every customer's statement with noise.
     */
    public function earn(int $partyId, int $rial, ?Model $reference = null, ?int $actorId = null): ?LoyaltyEntry
    {
        $rule = $this->activeRule();

        if (! $rule instanceof LoyaltyRule) {
            return null;
        }

        $points = $rule->pointsFor($rial);

        if ($points === 0) {
            return null;
        }

        return $this->record(
            $partyId,
            $points,
            LoyaltyEntry::REASON_EARN,
            "بابت خرید {$rule->name}",
            $reference,
            $actorId,
        );
    }

    /**
     * Spend points. Refuses to overdraw.
     *
     * @throws RuntimeException when the party does not have that many points
     */
    public function redeem(int $partyId, int $points, ?string $description = null, ?int $actorId = null): LoyaltyEntry
    {
        if ($points <= 0) {
            throw new RuntimeException('Redeeming a non-positive number of points is not a transaction.');
        }

        /** @var LoyaltyEntry $entry */
        $entry = $this->connection->transaction(function () use ($partyId, $points, $description, $actorId): LoyaltyEntry {
            $balance = $this->balanceFor($partyId);

            if ($balance < $points) {
                throw new RuntimeException("Party {$partyId} has {$balance} points and cannot redeem {$points}.");
            }

            return $this->record(
                $partyId,
                -$points,
                LoyaltyEntry::REASON_REDEEM,
                $description,
                null,
                $actorId,
            );
        });

        return $entry;
    }

    /**
     * An owner correcting someone's points by hand.
     *
     * The description is required by the caller's validation rather than defaulted: an
     * unexplained adjustment is the one entry a customer will definitely ask about.
     */
    public function adjust(int $partyId, int $points, string $description, ?int $actorId = null): LoyaltyEntry
    {
        if ($points === 0) {
            throw new RuntimeException('A zero-point adjustment records nothing.');
        }

        return $this->record($partyId, $points, LoyaltyEntry::REASON_MANUAL, $description, null, $actorId);
    }

    private function record(
        int $partyId,
        int $points,
        string $reason,
        ?string $description,
        ?Model $reference,
        ?int $actorId,
    ): LoyaltyEntry {
        /** @var LoyaltyEntry $entry */
        $entry = LoyaltyEntry::query()->create([
            'party_id' => $partyId,
            'points' => $points,
            'reason' => $reason,
            'description' => $description,
            'reference_type' => $reference === null ? null : $reference::class,
            'reference_id' => $reference?->getKey(),
            'actor_id' => $actorId,
            'occurred_at' => CarbonImmutable::now(),
        ]);

        return $entry;
    }
}
