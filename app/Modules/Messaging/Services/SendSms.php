<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\SmsDriver;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageOptOut;
use App\Modules\Messaging\Support\SmsPayload;
use App\Support\PhoneNumber;
use App\Support\Quota\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * The one door every message goes through.
 *
 * ## Every policy decision lives here, and only here
 *
 * Opt-out, credit, idempotency, number validity. Not in the drivers — a transport must not
 * decide whether to send, or the answer differs between the real gateway and the fake one
 * and the tests stop meaning anything. Not in the automations either: there are eight of
 * them and they would each get the check slightly wrong, and the one that got opt-out wrong
 * would be the birthday message.
 *
 * **Opt-out is checked here so it cannot be forgotten anywhere.** A customer who asked to
 * stop hearing from a shop and still gets a birthday SMS is the complaint that reaches the
 * regulator, and the reason it happens is always the same: somebody added a send path that
 * did not know about the list.
 *
 * ## A suppressed message is still a row
 *
 * Opted out, unsendable number, no credit — all three write a `suppressed` message with a
 * reason rather than returning null and vanishing. A shop asking «چرا برای این مشتری پیامک
 * نرفت؟» gets an answer, and an empty wallet is visible on the messages screen instead of
 * being a silence somebody has to guess at.
 *
 * ## Charge, then send, then refund on refusal
 *
 * See {@see SmsWallet} on why that order. The refund is exercised by a test that scripts a
 * gateway failure, because a refund path with no test is a wallet that drains on every
 * carrier outage.
 */
final class SendSms
{
    /** Rial per SMS segment. A platform default until per-tenant pricing lands. */
    public const DEFAULT_SEGMENT_COST = 3_000;

    public function __construct(
        private readonly SmsDriver $driver,
        private readonly SmsWallet $wallet,
        private readonly ConnectionInterface $connection,
        private readonly QuotaGuard $quota,
    ) {}

    /**
     * Queue, charge and send one pattern message.
     *
     * @param  list<string>  $tokens  positional, in template order
     * @param  string|null  $idempotencyKey  `birthday:{party}:1405` and the like — see
     *                                       docs/specs/treasury.md on the period-keyed pattern
     */
    public function send(
        ?string $rawPhone,
        string $templateId,
        array $tokens,
        ?string $templateKey = null,
        ?int $partyId = null,
        ?string $idempotencyKey = null,
        ?Model $reference = null,
        ?int $branchId = null,
        bool $systemMessage = false,
    ): ?Message {
        $to = PhoneNumber::normalise($rawPhone);

        if ($to === null) {
            // No number to send to. Recorded so a campaign's "12 of 400 skipped" can be
            // explained rather than merely counted.
            return $this->record(
                to: (string) ($rawPhone ?? ''),
                templateId: $templateId,
                tokens: $tokens,
                templateKey: $templateKey,
                partyId: $partyId,
                idempotencyKey: $idempotencyKey,
                reference: $reference,
                branchId: $branchId,
                status: Message::STATUS_SUPPRESSED,
                error: 'شماره موبایل معتبر نیست.',
            );
        }

        if ($this->hasOptedOut($to)) {
            return $this->record(
                to: $to, templateId: $templateId, tokens: $tokens, templateKey: $templateKey,
                partyId: $partyId, idempotencyKey: $idempotencyKey, reference: $reference,
                branchId: $branchId,
                status: Message::STATUS_SUPPRESSED,
                error: 'مشتری از دریافت پیامک انصراف داده است.',
            );
        }

        $message = $this->record(
            to: $to, templateId: $templateId, tokens: $tokens, templateKey: $templateKey,
            partyId: $partyId, idempotencyKey: $idempotencyKey, reference: $reference,
            branchId: $branchId,
            status: Message::STATUS_QUEUED,
        );

        // Already sent under this key. The unique index answered, which is the only place
        // the answer is safe from two workers asking at once.
        if ($message === null) {
            return null;
        }

        $cost = self::DEFAULT_SEGMENT_COST;

        /*
        | The monthly SMS credit — the one metric whose refusal NEVER throws.
        |
        | Almost every message here is sent by something the shopkeeper is not watching: a
        | queued job, an automation on a repair status, the nightly reminder sweep. A job
        | that threw on quota would retry, fail, and eventually alert — turning "you have
        | used your messages" into an incident. So it is `record()`, which returns a verdict
        | instead, and a refusal becomes a fifth suppression reason beside the four the shop
        | can already read in its own message log.
        |
        | `systemMessage` messages skip both this and the wallet: a message telling somebody
        | their credit is gone must not itself be refused for want of credit, and a password
        | reset must never be a thing a plan can withhold. The platform pays for those,
        | capped per shop per day (`hamyar.quota.system_sms_daily_cap`).
        */
        if (! $systemMessage) {
            $verdict = $this->quota->record('messaging.sms');

            if (! $verdict->allowed) {
                $message->forceFill([
                    'status' => Message::STATUS_SUPPRESSED,
                    'error' => 'سهمیهٔ پیامک این ماه تمام شده است.',
                ])->save();

                return $message;
            }
        }

        if (! $this->wallet->charge($message, $cost)) {
            $message->forceFill([
                'status' => Message::STATUS_SUPPRESSED,
                // Named plainly: a shop reading «اعتبار پیامک کافی نیست» tops up. A generic
                // "failed" sends them to support.
                'error' => 'اعتبار پیامک کافی نیست.',
            ])->save();

            return $message;
        }

        $message->forceFill(['cost' => $cost, 'driver' => $this->driver->name()])->save();

        $result = $this->driver->send(new SmsPayload(
            to: $to,
            templateId: $templateId,
            tokens: array_values($tokens),
            messageId: $message->id,
        ));

        if (! $result->accepted) {
            $this->wallet->refund($message, $cost, $result->error ?? 'خطای سامانه');

            $message->forceFill([
                'status' => Message::STATUS_FAILED,
                'error' => $result->error,
                'cost' => 0,
            ])->save();

            Log::warning('SMS rejected by gateway', ['message_id' => $message->getKey(), 'error' => $result->error]);

            return $message;
        }

        $message->forceFill([
            'status' => Message::STATUS_SENT,
            'provider_reference' => $result->reference,
            'segments' => $result->segments,
            'sent_at' => CarbonImmutable::now(),
        ])->save();

        return $message;
    }

    /**
     * Has this number asked to be left alone?
     *
     * Compared canonically. Four spellings of one number is how an opt-out silently fails.
     */
    public function hasOptedOut(string $canonicalPhone): bool
    {
        return MessageOptOut::query()->where('phone', $canonicalPhone)->exists();
    }

    /**
     * @param  list<string>  $tokens
     */
    private function record(
        string $to,
        string $templateId,
        array $tokens,
        ?string $templateKey,
        ?int $partyId,
        ?string $idempotencyKey,
        ?Model $reference,
        ?int $branchId,
        string $status,
        ?string $error = null,
    ): ?Message {
        try {
            /*
            | Wrapped in a transaction so the insert gets its own SAVEPOINT.
            |
            | Postgres aborts the whole transaction on a constraint violation, so catching
            | one inside an outer transaction — which every test has, and every automation
            | that batches sends will have — leaves that transaction dead and every
            | subsequent statement failing with 25P02. The nested call gives the insert a
            | savepoint to roll back to instead.
            |
            | Same shape as `AbandonedSweep::insertOnce()` in Phase 6, for the same reason.
            */
            /** @var Message $message */
            $message = $this->connection->transaction(fn (): Message => Message::query()->create([
                'branch_id' => $branchId,
                'party_id' => $partyId,
                'to' => $to,
                'template_key' => $templateKey,
                'provider_template_id' => $templateId,
                'tokens' => array_values($tokens),
                'status' => $status,
                'error' => $error,
                'idempotency_key' => $idempotencyKey,
                'reference_type' => $reference === null ? null : $reference::class,
                'reference_id' => $reference?->getKey(),
                'queued_at' => CarbonImmutable::now(),
            ]));

            return $message;
        } catch (QueryException $exception) {
            // 23505 — this key has already produced a message. A scheduler ran twice, or a
            // second worker got here first. Not an error: the answer is "already done".
            if ($idempotencyKey !== null && $exception->getCode() === '23505') {
                return null;
            }

            throw $exception;
        }
    }
}
