<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\SmsCreditEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * The shop's SMS credit — pre-paid, real money, spent one message at a time.
 *
 * ## Charge before sending, refund if the gateway refuses
 *
 * The order is deliberate and it is the opposite of what feels natural. Sending first and
 * charging after loses the charge whenever a worker dies mid-send, and the shop gets
 * messages it never paid for — which sounds like the shop's good luck until it is a
 * thousand of them and the platform is carrying the cost.
 *
 * Charging first means a rejected send must give the money back, so `refund()` exists and
 * is exercised by a test that scripts a gateway failure. **A refund path with no test is a
 * wallet that quietly drains on every carrier outage.**
 *
 * The refund is its own entry rather than a deletion of the charge: the trail then shows
 * that a message was attempted and failed, which is what a shop asking "why did you charge
 * me twice on Tuesday" needs to see.
 *
 * ## The balance is a SUM
 *
 * Golden rule 3, and here it is money somebody paid. A stored balance drifts in the
 * direction of the shop having paid for messages it cannot account for.
 *
 * ## Insufficient credit suppresses rather than throws
 *
 * A repair marked ready must not fail because the SMS wallet is empty. The message is
 * recorded `suppressed` with a reason, the shop sees it on the messages screen and tops up,
 * and the repair goes on being ready. A shop's operations do not stop because a
 * notification could not go out.
 */
final class SmsWallet
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function balance(): int
    {
        $sum = SmsCreditEntry::query()->sum('amount');

        return is_numeric($sum) ? (int) $sum : 0;
    }

    public function canAfford(int $cost): bool
    {
        return $cost <= 0 || $this->balance() >= $cost;
    }

    public function topUp(int $amount, ?string $description = null, ?int $actorId = null): SmsCreditEntry
    {
        if ($amount <= 0) {
            throw new RuntimeException('مبلغ شارژ باید بیشتر از صفر باشد.');
        }

        return $this->write(SmsCreditEntry::TYPE_TOPUP, $amount, $description ?? 'شارژ اعتبار پیامک', null, $actorId);
    }

    /**
     * Take the cost of one message.
     *
     * Returns false when there is not enough credit — the caller suppresses rather than
     * failing, so a shop's operations continue with an empty wallet.
     */
    public function charge(Message $message, int $cost): bool
    {
        if ($cost <= 0) {
            return true;
        }

        return (bool) $this->connection->transaction(function () use ($message, $cost): bool {
            /*
            | Read the balance inside the transaction.
            |
            | Two messages queued in the same instant against a wallet holding enough for
            | one would both see sufficient credit outside it. This is not a lock on a
            | balance row — there is no balance row — so it is a serialisation point rather
            | than a guarantee, and the wallet may go slightly negative under extreme
            | concurrency. That is the right trade: refusing to send a customer's «آماده
            | است» because two messages raced is worse than a shop being 3,000 rial down.
            */
            if ($this->balance() < $cost) {
                return false;
            }

            $this->write(
                SmsCreditEntry::TYPE_CHARGE,
                -$cost,
                "ارسال پیامک به {$message->to}",
                $message,
            );

            return true;
        });
    }

    /**
     * Give it back — the gateway refused.
     *
     * Its own entry, not a deletion: the trail must show the attempt and its reversal.
     */
    public function refund(Message $message, int $cost, string $reason): ?SmsCreditEntry
    {
        if ($cost <= 0) {
            return null;
        }

        return $this->write(
            SmsCreditEntry::TYPE_REFUND,
            $cost,
            "برگشت هزینه پیامک — {$reason}",
            $message,
        );
    }

    private function write(string $type, int $amount, string $description, ?Message $reference = null, ?int $actorId = null): SmsCreditEntry
    {
        /** @var SmsCreditEntry $entry */
        $entry = SmsCreditEntry::query()->create([
            'amount' => $amount,
            'type' => $type,
            'description' => $description,
            'reference_type' => $reference === null ? null : $reference::class,
            'reference_id' => $reference?->getKey(),
            'actor_id' => $actorId,
            'occurred_at' => CarbonImmutable::now(),
        ]);

        return $entry;
    }
}
