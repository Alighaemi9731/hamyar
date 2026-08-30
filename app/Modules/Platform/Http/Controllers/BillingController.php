<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\PaymentAttempt;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\SubscriptionInvoice;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\BillingService;
use App\Modules\Platform\Services\Payments\PaymentGatewayException;
use App\Modules\Platform\Services\ProrationCalculator;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Support\ReturnPath;
use App\Support\Money;
use App\Support\Quota\MetricRegistry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The shop's own billing screens.
 *
 * The screens sit behind `tenant` and read the shop from the session like every other
 * signed-in page. {@see callback()} is the exception and the interesting one: it is the
 * address the payment gateway returns a customer to, so it must work with no session at
 * all — see its own docblock for where the shop comes from instead.
 */
final class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly SubscriptionResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request, ProrationCalculator $proration): Response
    {
        $this->authorize('viewAny', Subscription::class);

        $subscription = $this->resolver->current();
        $now = CarbonImmutable::now();

        $plans = Plan::query()
            ->with('limits')
            ->where('is_public', true)
            ->orderBy('position')
            ->get();

        $metrics = app(MetricRegistry::class)->all();

        return Inertia::render('platform/billing/index', [
            'subscription' => $subscription === null ? null : [
                'plan_code' => $subscription->plan->code,
                'plan_name' => $subscription->plan->name_fa,
                'status' => $subscription->status,
                'is_trialing' => $subscription->isTrialing($now),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'credit_balance' => Money::toArray($subscription->credit_balance),
            ],
            'plans' => $plans->map(fn (Plan $plan): array => [
                'code' => $plan->code,
                'name' => $plan->name_fa,
                'tagline' => $plan->tagline_fa,
                'price' => Money::toArray($plan->price),
                // What the plan lets a shop DO each month, not which parts of the product
                // it may open — every module is open on every plan since DECISION GATE 6.
                'limits' => array_values(array_map(static fn ($metric): array => [
                    'key' => $metric->key,
                    'label' => $metric->labelFa,
                    'unit' => $metric->unitFa,
                    'window' => $metric->window->value,
                    'value' => $plan->limit($metric->key),
                ], $metrics)),
                'is_current' => $subscription?->plan_id === $plan->getKey(),
                // What switching would actually cost today, so the shop is never
                // surprised at the gateway (ADR 0006).
                'change' => $subscription === null ? null : $this->previewFor($proration, $subscription, $plan, $now),
            ])->values()->all(),
            'invoices' => $this->recentInvoices(),

            /*
            | `?upgrade=<code>&return_to=<path>` — the block card's link.
            |
            | A shop arriving here from a quota block is not browsing plans; it has already
            | been told which one clears the wall it just hit, and it is mid-task. So the
            | page highlights that rung and carries the path forward, and the shop presses
            | one button instead of re-deciding something it was already told.
            |
            | Both are validated: the code must name a public plan (otherwise the page
            | would highlight nothing and look broken), and the path goes through
            | `ReturnPath` like every other copy of it.
            */
            'highlight' => $this->highlightedPlan($request, $plans),
            'return_to' => ReturnPath::sanitise($request->query('return_to')),
        ]);
    }

    /**
     * The plan code to draw attention to, if it names a real public plan.
     *
     * @param  Collection<int, Plan>  $plans
     */
    private function highlightedPlan(Request $request, Collection $plans): ?string
    {
        $code = $request->query('upgrade');

        if (! is_string($code) || $code === '') {
            return null;
        }

        // Checked against the list the page actually renders, not against the plans table:
        // highlighting a private or archived plan would draw the eye to a card that is not
        // on the screen.
        return $plans->contains(static fn (Plan $plan): bool => $plan->code === $code) ? $code : null;
    }

    /**
     * Draft an invoice for the chosen plan and send the shop to the gateway.
     */
    public function subscribe(Request $request, Plan $plan): RedirectResponse
    {
        $this->authorize('manage', Subscription::class);

        $tenant = $this->context->tenant();

        if ($tenant === null) {
            abort(404);
        }

        $invoice = $this->billing->invoiceForPlan($tenant, $plan);

        /*
        | Where they were when they pressed «ارتقا».
        |
        | A shop blocked at the till upgrades to finish the sale it is halfway through, so
        | the receipt is the wrong place to land: they would have to walk back and retype a
        | basket they had already built, and the upgrade would read as not having worked.
        |
        | Sanitised by `BillingService` rather than here, so the one column that ends at
        | `redirect()` can only ever hold values that were checked — see {@see ReturnPath}
        | on why this is an open-redirect hole otherwise.
        */
        $returnTo = ReturnPath::sanitise($request->input('return_to'));

        // Fully covered by credit, or a free plan: nothing to pay, so skip the gateway
        // round trip entirely rather than sending someone to pay zero rial.
        if (! $invoice->requiresPayment()) {
            $this->billing->settleWithoutPayment($invoice);

            // Same courtesy on the free path: the shop upgraded and the credit is theirs
            // now, so put them back on the screen that stopped them either way.
            if ($returnTo !== null) {
                return redirect()->to($returnTo)
                    ->with('success', 'اشتراک شما با استفاده از اعتبار موجود فعال شد.');
            }

            return redirect()
                ->route('billing.receipt', ['invoice' => $invoice->getKey()])
                ->with('success', 'اشتراک شما با استفاده از اعتبار موجود فعال شد.');
        }

        try {
            $redirect = $this->billing->initiatePayment(
                $invoice,
                route('billing.callback', absolute: true),
                $returnTo,
            );
        } catch (PaymentGatewayException $exception) {
            report($exception);

            return back()->with('error', 'ارتباط با درگاه پرداخت برقرار نشد. لطفاً چند لحظه بعد دوباره تلاش کنید.');
        }

        return redirect()->away($redirect->url);
    }

    /**
     * Where the gateway sends the customer back.
     *
     * Deliberately NOT behind `auth`, and — since [ADR 0017](../../../../docs/adr/0017-single-host-app.md)
     * — not behind `tenant` either. The customer may return in a different browser
     * context, and refusing the callback because a session expired would leave a paid
     * invoice unsettled. That used to cost nothing, because the shop's hostname named the
     * tenant; with one address for everybody the `tenant` middleware answers a 302 to
     * /login instead, and the payment is stranded with no error anywhere.
     *
     * So the shop is resolved here, from the attempt row the authority already identifies,
     * and entered before anything is settled — see {@see tenantForAuthority()}.
     * Verification still authorises itself: an authority we did not issue is rejected, and
     * one we did can only be settled once.
     */
    public function callback(Request $request): RedirectResponse
    {
        /** @var string $authority */
        $authority = (string) ($request->query('Authority') ?? $request->query('authority') ?? '');

        if ($authority === '') {
            return redirect()->route('billing.index')->with('error', 'پاسخ درگاه پرداخت نامعتبر بود.');
        }

        $tenant = $this->tenantForAuthority($authority);

        if (! $tenant instanceof Tenant) {
            // An authority we never issued, or one whose shop is gone. Reported, because
            // it means either a forged return or a broken restore, and answered with the
            // same message as a failed verification — the customer can act on neither
            // difference, and spelling it out would confirm which authorities exist.
            report(new RuntimeException("Unknown payment authority [{$authority}]."));

            return redirect()->route('billing.index')
                ->with('error', 'تأیید پرداخت ممکن نشد. اگر مبلغ از حساب شما کسر شده، تا ۷۲ ساعت بازمی‌گردد.');
        }

        try {
            /*
            | `runFor`, not `set`. The context is restored in a `finally`, so a throw on
            | the way through cannot leave this process pinned to a shop it was only
            | passing through — there is no ResolveTenant on this route to clear it at the
            | end of the request any more.
            */
            $attempt = $this->context->runFor(
                $tenant,
                fn (): PaymentAttempt => $this->billing->verifyCallback($authority, $request->query())
            );
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()->route('billing.index')
                ->with('error', 'تأیید پرداخت ممکن نشد. اگر مبلغ از حساب شما کسر شده، تا ۷۲ ساعت بازمی‌گردد.');
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('billing.index')->with('error', 'خطای غیرمنتظره در پردازش پرداخت.');
        }

        if (! $attempt->isVerified()) {
            return redirect()->route('billing.index')
                ->with('error', $attempt->error ?? 'پرداخت ناموفق بود.');
        }

        /*
        | Home, if they told us where that was.
        |
        | Re-sanitised on the way out even though it was sanitised on the way in. The value
        | has been sitting in a database across a round trip through somebody else's
        | website, and this is the line that hands it to `redirect()`; a check at the point
        | of use costs nothing and does not depend on every future writer of this column
        | having remembered.
        |
        | The receipt is still reachable — the success message names the plan and the
        | billing page lists every invoice — so nothing is lost by going back to work.
        */
        $returnTo = ReturnPath::sanitise($attempt->return_to);

        if ($returnTo !== null) {
            return redirect()->to($returnTo)->with('success', 'پرداخت انجام شد؛ می‌توانید ادامه دهید.');
        }

        return redirect()
            ->route('billing.receipt', ['invoice' => $attempt->subscription_invoice_id])
            ->with('success', 'پرداخت با موفقیت انجام شد.');
    }

    public function receipt(int $invoice): Response
    {
        $this->authorize('viewAny', Subscription::class);

        $tenantId = $this->context->id();

        $record = $this->context->runAsPlatform(
            fn (): ?SubscriptionInvoice => SubscriptionInvoice::query()
                ->with('attempts')
                ->where('tenant_id', $tenantId)
                ->whereKey($invoice)
                ->first()
        );

        // Scoped by tenant_id above as well as by RLS. A receipt is the one billing
        // screen a shop links to, so it is the one most likely to be probed by id.
        if (! $record instanceof SubscriptionInvoice) {
            abort(404);
        }

        /** @var PaymentAttempt|null $verified */
        $verified = $record->attempts->first(static fn (PaymentAttempt $a): bool => $a->isVerified());

        return Inertia::render('platform/billing/receipt', [
            'invoice' => [
                'number' => $record->number,
                'status' => $record->status,
                'subtotal' => Money::toArray($record->subtotal),
                'discount' => Money::toArray($record->discount),
                'credit_applied' => Money::toArray($record->credit_applied),
                'total' => Money::toArray($record->total),
                'paid_at' => $record->paid_at?->toIso8601String(),
                'lines' => array_map(static fn (array $line): array => [
                    'label' => (string) $line['label'],
                    'amount' => Money::toArray((int) $line['amount']),
                ], $record->lines),
                'reference' => $verified?->reference,
            ],
        ]);
    }

    /**
     * Which shop a gateway callback belongs to.
     *
     * The customer comes back to one address with no session (ADR 0017), so the tenant
     * cannot come from the request — and it must not, or the return URL would become a
     * way to nominate a shop. It comes from the `payment_attempts` row the authority
     * already identifies: `payment_attempts_authority_unique` is globally unique BY
     * DESIGN and listed as such in `TenancyCheckCommand`, which is the category for
     * exactly this — a value whose whole job is to resolve before any tenant is known.
     *
     * `runAsPlatform()` because the read happens with nothing pinned, and RLS then denies
     * every row. `payment_attempts` opted into the flag in its own migration
     * (`enableRls(..., allowPlatform: true)`), so this is the narrow escape of the ADR
     * 0002 amendment rather than a bypass. Without it the row is invisible, the callback
     * looks like a forged authority, and a real payment is silently stranded.
     */
    private function tenantForAuthority(string $authority): ?Tenant
    {
        $attempt = $this->context->runAsPlatform(
            static fn (): ?PaymentAttempt => PaymentAttempt::query()->where('authority', $authority)->first()
        );

        if (! $attempt instanceof PaymentAttempt) {
            return null;
        }

        // `tenants` is central, not tenant-scoped, so this one needs no escape.
        return Tenant::query()->find($attempt->tenant_id);
    }

    /**
     * @return array{kind: string, amount_due: array{value: int, formatted: string}, effective_at: string}|null
     */
    private function previewFor(ProrationCalculator $proration, Subscription $subscription, Plan $plan, CarbonImmutable $now): ?array
    {
        if ($subscription->plan_id === $plan->getKey()) {
            return null;
        }

        $preview = $proration->preview($subscription, $plan, $now);

        return [
            'kind' => $preview['kind'],
            'amount_due' => Money::toArray($preview['amount_due']),
            'effective_at' => $preview['effective_at'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentInvoices(): array
    {
        $tenantId = $this->context->id();

        $rows = $this->context->runAsPlatform(
            fn (): array => SubscriptionInvoice::query()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit(12)
                ->get()
                ->map(static fn (SubscriptionInvoice $invoice): array => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status,
                    'total' => Money::toArray($invoice->total),
                    'paid_at' => $invoice->paid_at?->toIso8601String(),
                    'created_at' => $invoice->created_at?->toIso8601String(),
                ])
                ->values()
                ->all()
        );

        return array_values($rows);
    }
}
