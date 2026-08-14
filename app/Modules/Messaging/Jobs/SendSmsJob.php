<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Services\SendSms;
use App\Support\Tenancy\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One message, sent off the request thread.
 *
 * ## Why sending is queued at all
 *
 * A gateway call is a network round trip to somebody else's server. Doing it inline means
 * a repair marked ready waits on Kavenegar's response time, and a gateway having a bad
 * afternoon makes the shop's own software feel broken. The operator should see «ثبت شد» and
 * carry on.
 *
 * ## The tenant travels with the job
 *
 * {@see TenantAware} captures the dispatching tenant into `$tenantId`, and
 * {@see \App\Support\Tenancy\RestoreTenantContext} enters it before `handle()` and clears
 * it after. Without that, a worker processing tenant A then tenant B would run B's message
 * with A's connection variable still set — and RLS would answer with A's wallet, A's
 * templates and A's opt-out list. The result is not an error: it is a message that
 * silently draws on the wrong shop's credit.
 *
 * `MessagingTenantIsolationTest` interleaves two tenants' jobs on one worker and asserts
 * each resolves its own wallet and opt-out list, because that is the failure this rule
 * exists to prevent and it cannot be observed from a single-tenant test.
 *
 * ## Retries do not double-charge
 *
 * `SendSms` writes the message row under an idempotency key before it charges. A retry
 * after a worker crash finds the key taken and stops — the second attempt sends nothing
 * and spends nothing. Automations always pass a key; an ad-hoc send from a screen does not,
 * because a person pressing "send" twice means it twice.
 */
final class SendSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAware;

    /**
     * Three attempts, then it stays failed.
     *
     * A gateway rejection is not retried — `SendSms` records it and refunds. This covers
     * the transport dying mid-call, which is worth a second try and not a tenth.
     */
    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  list<string>  $tokens
     */
    public function __construct(
        private readonly ?string $phone,
        private readonly string $templateId,
        private readonly array $tokens,
        private readonly ?string $templateKey = null,
        private readonly ?int $partyId = null,
        private readonly ?string $idempotencyKey = null,
        private readonly ?int $branchId = null,
    ) {
        $this->initializeTenantAware();
        $this->onQueue('sms');
    }

    public function handle(SendSms $sender): void
    {
        $sender->send(
            $this->phone,
            $this->templateId,
            $this->tokens,
            templateKey: $this->templateKey,
            partyId: $this->partyId,
            idempotencyKey: $this->idempotencyKey,
            branchId: $this->branchId,
        );
    }

    /**
     * Deduplicate at the queue as well as in the database.
     *
     * The unique index is the guarantee; this is the cheap first line that stops a
     * thousand identical jobs from a mis-fired scheduler ever reaching a worker.
     */
    public function uniqueId(): string
    {
        return $this->idempotencyKey ?? (string) spl_object_id($this);
    }
}
