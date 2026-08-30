<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Events\SubscriptionActivated;
use App\Modules\Platform\Events\SubscriptionInvoicePaid;
use App\Modules\Platform\Models\PaymentAttempt;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\SubscriptionInvoice;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\Payments\GatewayRedirect;
use App\Modules\Platform\Services\Payments\GatewayVerification;
use App\Modules\Platform\Services\Payments\PaymentGateway;
use App\Modules\Platform\Support\ReturnPath;
use App\Support\Counters\Counter;
use App\Support\Counters\CounterService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Turning a plan choice into an invoice, a payment, and access.
 *
 * Everything here runs inside `TenantContext::runAsPlatform()`, because the billing
 * tables are platform-owned (ADR 0002 amendment) and a callback from Zarinpal arrives
 * with no session and therefore no tenant context at all.
 *
 * ## The idempotency rule
 *
 * A payment callback is not a reliable, once-only event. The shop refreshes the return
 * page; the gateway retries; someone hits the back button. Every one of those replays
 * the callback with the same authority. So verification is built around a single
 * invariant: **an authority can be settled exactly once.**
 *
 * It is enforced in three layers, not one, because each covers a case the others miss:
 *
 * 1. A UNIQUE index on `payment_attempts.authority` — two attempts can never share one.
 * 2. `SELECT … FOR UPDATE` on the attempt inside the verifying transaction, so two
 *    simultaneous callbacks serialise instead of both reading `initiated`.
 * 3. A status check under that lock. The second caller sees `verified` and returns the
 *    original result rather than applying the payment again.
 *
 * Layer 2 is the one people skip. Without it, two concurrent callbacks both read
 * `initiated`, both pass the status check, and the subscription is extended twice — the
 * shop gets two months for one payment, and it will not be reported.
 */
final class BillingService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TenantContext $context,
        private readonly CounterService $counters,
        private readonly PaymentGateway $gateway,
        private readonly ProrationCalculator $proration,
    ) {}

    /**
     * Draft the invoice for putting `$tenant` on `$plan`.
     *
     * Applies any stored credit before the total, so a shop that downgraded last month
     * sees the credit consumed rather than having to ask where it went.
     */
    public function invoiceForPlan(Tenant $tenant, Plan $plan, ?CarbonImmutable $now = null): SubscriptionInvoice
    {
        $now ??= CarbonImmutable::now();

        $invoice = $this->inTenantAndPlatformContext($tenant, function () use ($tenant, $plan, $now): SubscriptionInvoice {
            /** @var int $tenantId */
            $tenantId = $tenant->getKey();

            $subscription = $this->activeSubscription($tenantId);

            // Proration applies only while there is a period left to prorate. Once
            // `current_period_end` has passed, the shop is buying a FRESH period and owes
            // the full price — running the proration formula on an expired subscription
            // yields zero remaining days, hence a zero invoice, and a lapsed shop would
            // renew for free.
            [$subtotal, $lines] = $subscription instanceof Subscription && $this->hasLivePeriod($subscription, $now)
                ? $this->upgradeLines($subscription, $plan, $now)
                : [$plan->price, [['label' => $plan->name_fa, 'amount' => $plan->price]]];

            $credit = $subscription instanceof Subscription ? $subscription->credit_balance : 0;
            $creditApplied = min($credit, $subtotal);

            $invoice = SubscriptionInvoice::query()->create([
                'tenant_id' => $tenantId,
                'subscription_id' => $subscription?->getKey(),
                // What the money buys, in a form the settlement path can act on. The
                // `lines` snapshot below is what the invoice SAYS and never changes;
                // this is what it MEANS. Without it `applyPayment()` extended the period
                // and left the shop on its old plan — an upgrade that took the money and
                // changed nothing.
                'plan_id' => $plan->getKey(),
                // No branch: the platform bills the shop, not one of its shopfronts.
                'number' => $this->counters->nextFormatted(
                    $tenantId, Counter::SUBSCRIPTION_INVOICE, 'SUB', branchId: null, pad: 5
                ),
                'subtotal' => $subtotal,
                'discount' => 0,
                'credit_applied' => $creditApplied,
                'total' => $subtotal - $creditApplied,
                'status' => SubscriptionInvoice::STATUS_PENDING,
                'lines' => $lines,
            ]);

            // Reserve the credit now. Leaving it on the subscription until payment lets a
            // shop spend the same credit on two open invoices.
            if ($creditApplied > 0 && $subscription instanceof Subscription) {
                $subscription->decrement('credit_balance', $creditApplied);
            }

            return $invoice;
        });

        return $invoice;
    }

    /**
     * Run a callback inside BOTH the tenant's context and the platform flag, in one
     * transaction.
     *
     * Drafting an invoice touches tables on both sides of the ownership line: the number
     * comes from `counters`, an ordinary tenant table with an ordinary policy, while the
     * invoice itself is platform-owned. Missing either context means one of the two
     * writes is denied by RLS.
     *
     * Neither context is inherited from the caller. This runs from a tenant request, from
     * the Filament panel and from a queued renewal, and the ambient context differs every
     * time — depending on it is how you get a method that works in the browser and fails
     * in a job.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function inTenantAndPlatformContext(Tenant $tenant, Closure $callback): mixed
    {
        return $this->context->runFor(
            $tenant,
            fn (): mixed => $this->context->runAsPlatform(
                fn (): mixed => $this->connection->transaction($callback)
            )
        );
    }

    /**
     * Register the invoice with the gateway and get somewhere to send the customer.
     */
    /**
     * @param  string|null  $returnTo  where to put the shopkeeper back afterwards. Sanitised
     *                                 here rather than trusted, because this ends at
     *                                 `redirect()` after a round trip through a payment
     *                                 gateway — see {@see ReturnPath}. Passing an unsafe
     *                                 value is not an error; it simply does not travel.
     */
    public function initiatePayment(SubscriptionInvoice $invoice, string $callbackUrl, ?string $returnTo = null): GatewayRedirect
    {
        if ($invoice->isPaid()) {
            throw new RuntimeException("Invoice {$invoice->number} is already paid.");
        }

        if (! $invoice->requiresPayment()) {
            throw new RuntimeException("Invoice {$invoice->number} has nothing to pay.");
        }

        // Sanitised at the boundary, so nothing downstream has to remember to. The column
        // holds only values that were safe at the moment they were written.
        $safeReturn = ReturnPath::sanitise($returnTo);

        return $this->context->runAsPlatform(function () use ($invoice, $callbackUrl, $safeReturn): GatewayRedirect {
            $attempt = PaymentAttempt::query()->create([
                'tenant_id' => $invoice->tenant_id,
                'subscription_invoice_id' => $invoice->getKey(),
                'gateway' => $this->gateway->name(),
                'return_to' => $safeReturn,
                'amount' => $invoice->total,
                'status' => PaymentAttempt::STATUS_INITIATED,
            ]);

            $attempt->setRelation('invoice', $invoice);

            $redirect = $this->gateway->initiate($attempt, $callbackUrl);

            $attempt->update(['authority' => $redirect->authority]);

            return $redirect;
        });
    }

    /**
     * Mark a zero-total invoice paid without involving a gateway.
     *
     * Happens when stored credit or a full-value coupon covers the whole amount. The
     * subscription must still be extended, so this runs the same `applyPayment()` path a
     * real payment does rather than a shortcut that would drift from it.
     */
    public function settleWithoutPayment(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        /** @var int $invoiceId */
        $invoiceId = $invoice->getKey();

        if ($invoice->requiresPayment()) {
            throw new RuntimeException("Invoice {$invoice->number} still has {$invoice->total} rial to pay.");
        }

        /** @var SubscriptionInvoice $settled */
        $settled = $this->context->runAsPlatform(fn (): mixed => $this->connection->transaction(function () use ($invoice, $invoiceId): SubscriptionInvoice {
            $attempt = PaymentAttempt::query()->create([
                'tenant_id' => $invoice->tenant_id,
                'subscription_invoice_id' => $invoice->getKey(),
                // Recorded as an attempt so the audit trail shows *why* an invoice was
                // settled with no money — an invoice that just turns paid looks like a bug.
                'gateway' => 'credit',
                'authority' => 'CREDIT-'.$invoiceId,
                'amount' => 0,
                'status' => PaymentAttempt::STATUS_VERIFIED,
                'verified_at' => CarbonImmutable::now(),
                'payload' => ['reason' => 'covered_by_credit'],
            ]);

            $attempt->setRelation('invoice', $invoice);

            $this->applyPayment($attempt);

            return $invoice->refresh();
        }));

        return $settled;
    }

    /**
     * Settle a callback. Safe to call repeatedly with the same authority.
     *
     * @param  array<string, mixed>  $callback
     */
    public function verifyCallback(string $authority, array $callback): PaymentAttempt
    {
        /** @var PaymentAttempt $attempt */
        $attempt = $this->context->runAsPlatform(fn (): mixed => $this->connection->transaction(function () use ($authority, $callback): PaymentAttempt {
            // Layer 2: the lock. Everything below assumes no other request can be inside
            // this block for the same authority at the same time.
            $attempt = PaymentAttempt::query()
                ->where('authority', $authority)
                ->lockForUpdate()
                ->first();

            if (! $attempt instanceof PaymentAttempt) {
                throw new RuntimeException("Unknown payment authority [{$authority}].");
            }

            // Layer 3: a replay. The money already moved and access was already granted;
            // say so again without touching anything.
            if (! $attempt->isPending()) {
                return $attempt;
            }

            $verification = $this->gateway->verify($attempt, $callback);

            if (! $verification->paid) {
                $attempt->update([
                    'status' => PaymentAttempt::STATUS_FAILED,
                    'error' => $verification->error,
                    'payload' => $verification->payload,
                ]);

                return $attempt;
            }

            $this->guardAmount($attempt, $verification);

            $attempt->update([
                'status' => PaymentAttempt::STATUS_VERIFIED,
                'reference' => $verification->reference,
                'payload' => $verification->payload,
                'verified_at' => CarbonImmutable::now(),
            ]);

            $this->applyPayment($attempt);

            return $attempt;
        }));

        return $attempt;
    }

    /**
     * A verified payment for the wrong amount is not a payment we can accept silently.
     *
     * The classic attack is tampering with the amount between our request and the
     * gateway's: pay ۱۰,۰۰۰ ریال, get a plan worth ۵,۹۰۰,۰۰۰. Zarinpal echoes the amount
     * it settled, so we compare and refuse rather than trust the callback.
     */
    private function guardAmount(PaymentAttempt $attempt, GatewayVerification $verification): void
    {
        if ($verification->amount !== null && $verification->amount !== $attempt->amount) {
            throw new RuntimeException(
                "Amount mismatch on authority [{$attempt->authority}]: "
                ."gateway settled {$verification->amount}, invoice expects {$attempt->amount}."
            );
        }
    }

    /**
     * Mark the invoice paid and extend or start the subscription.
     *
     * Called only from inside the locked transaction above, which is what makes "extend
     * by one period" safe to write as a read-modify-write.
     */
    private function applyPayment(PaymentAttempt $attempt): void
    {
        $invoice = $attempt->invoice()->lockForUpdate()->firstOrFail();

        if ($invoice->isPaid()) {
            return;
        }

        $invoice->update([
            'status' => SubscriptionInvoice::STATUS_PAID,
            'paid_at' => CarbonImmutable::now(),
        ]);

        $now = CarbonImmutable::now();
        $subscription = $invoice->subscription;

        if (! $subscription instanceof Subscription) {
            // A paid invoice with nothing to grant. Reachable when the shop had no
            // subscription row at all when it bought — provisioning normally makes one,
            // but "we took the money and the shop got nothing" is not a failure mode to
            // leave to chance, and the invoice now knows which plan it was for.
            $subscription = $this->startSubscriptionFor($invoice, $now);

            if (! $subscription instanceof Subscription) {
                event(new SubscriptionInvoicePaid($invoice));

                return;
            }

            $invoice->update(['subscription_id' => $subscription->getKey()]);
        }

        // An upgrade keeps its renewal date (ADR 0006) — the period only rolls forward
        // when the one being paid for has actually run out.
        $end = $subscription->current_period_end;
        $extendFrom = $end instanceof CarbonImmutable && $end->greaterThan($now) ? $end : $now;

        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $subscription->current_period_start ?? $now,
            'current_period_end' => $end instanceof CarbonImmutable && $end->greaterThan($now)
                ? $end
                : $extendFrom->addMonth(),
            'grace_ends_at' => null,
            // THE fix. Everything else here was already right; this line is why an
            // upgrade is an upgrade. `plan_changed_at` moves with it so support can
            // answer "since when" without reading invoices.
            ...$this->planChange($invoice, $subscription, $now),
        ]);

        event(new SubscriptionInvoicePaid($invoice));
        event(new SubscriptionActivated($subscription));
    }

    /**
     * The plan half of the settlement update, or nothing.
     *
     * Separated because it has three cases and each is a decision: the invoice names no
     * plan (written before the column existed — extend the period, change nothing); it
     * names the plan the shop is already on (a renewal — no change, and no misleading
     * `plan_changed_at`); or it names a different one (the upgrade, which is the whole
     * point of the column).
     *
     * @return array<string, mixed>
     */
    private function planChange(SubscriptionInvoice $invoice, Subscription $subscription, CarbonImmutable $now): array
    {
        $planId = $invoice->plan_id;

        if ($planId === null || $planId === $subscription->plan_id) {
            return [];
        }

        return ['plan_id' => $planId, 'plan_changed_at' => $now];
    }

    /**
     * Create the subscription a paid invoice implies, when the shop had none.
     *
     * Returns null only when the invoice cannot say which plan it bought, which is true
     * exactly of rows written before `plan_id` existed.
     */
    private function startSubscriptionFor(SubscriptionInvoice $invoice, CarbonImmutable $now): ?Subscription
    {
        if ($invoice->plan_id === null) {
            return null;
        }

        return Subscription::query()->create([
            'tenant_id' => $invoice->tenant_id,
            'plan_id' => $invoice->plan_id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $now,
            'current_period_end' => $now->addMonth(),
            'plan_changed_at' => $now,
        ]);
    }

    /**
     * @return array{0: int, 1: list<array{label: string, amount: int}>}
     */
    private function upgradeLines(Subscription $subscription, Plan $plan, CarbonImmutable $now): array
    {
        $preview = $this->proration->preview($subscription, $plan, $now);

        if ($preview['kind'] !== 'upgrade') {
            return [$plan->price, [['label' => $plan->name_fa, 'amount' => $plan->price]]];
        }

        return [$preview['amount_due'], [
            ['label' => "{$plan->name_fa} — {$preview['remaining_days']} روز باقیمانده", 'amount' => $preview['new_charge']],
            ['label' => 'اعتبار دوره استفاده‌نشده', 'amount' => -$preview['unused_credit']],
        ]];
    }

    /**
     * Is there still paid-for time left to prorate against?
     *
     * A trial does not count: it was free, so there is no unused value to credit, and
     * ADR 0006 already says an upgrade during a trial costs nothing now and bills the
     * full price from the first real period.
     */
    private function hasLivePeriod(Subscription $subscription, CarbonImmutable $now): bool
    {
        if ($subscription->isTrialing($now)) {
            return false;
        }

        $end = $subscription->current_period_end;

        return $end instanceof CarbonImmutable && $end->greaterThan($now);
    }

    private function activeSubscription(int $tenantId): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->first();
    }
}
