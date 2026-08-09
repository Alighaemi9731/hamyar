<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\PaymentAttempt;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\SubscriptionInvoice;
use App\Modules\Platform\Services\BillingService;
use App\Modules\Platform\Services\Payments\PaymentGatewayException;
use App\Modules\Platform\Services\ProrationCalculator;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The shop's own billing screens.
 *
 * Lives on the tenant subdomain rather than the central domain so the gateway returns
 * the customer to the shop they were already logged into — a callback landing on the
 * central domain would arrive with no session and no way to tell which shop paid.
 */
final class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly SubscriptionResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function index(ProrationCalculator $proration): Response
    {
        $this->authorize('viewAny', Subscription::class);

        $subscription = $this->resolver->current();
        $now = CarbonImmutable::now();

        $plans = Plan::query()
            ->with(['modules', 'limits'])
            ->where('is_public', true)
            ->orderBy('position')
            ->get();

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
                'modules' => $plan->modules->pluck('name_fa')->values()->all(),
                'is_current' => $subscription?->plan_id === $plan->getKey(),
                // What switching would actually cost today, so the shop is never
                // surprised at the gateway (ADR 0006).
                'change' => $subscription === null ? null : $this->previewFor($proration, $subscription, $plan, $now),
            ])->values()->all(),
            'invoices' => $this->recentInvoices(),
        ]);
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

        // Fully covered by credit, or a free plan: nothing to pay, so skip the gateway
        // round trip entirely rather than sending someone to pay zero rial.
        if (! $invoice->requiresPayment()) {
            $this->billing->settleWithoutPayment($invoice);

            return redirect()
                ->route('billing.receipt', ['invoice' => $invoice->getKey()])
                ->with('success', 'اشتراک شما با استفاده از اعتبار موجود فعال شد.');
        }

        try {
            $redirect = $this->billing->initiatePayment(
                $invoice,
                route('billing.callback', absolute: true)
            );
        } catch (PaymentGatewayException $exception) {
            report($exception);

            return back()->with('error', 'ارتباط با درگاه پرداخت برقرار نشد. لطفاً چند لحظه بعد دوباره تلاش کنید.');
        }

        unset($request);

        return redirect()->away($redirect->url);
    }

    /**
     * Where the gateway sends the customer back.
     *
     * Deliberately NOT behind `auth`. The customer may return in a different browser
     * context, and refusing the callback because a session expired would leave a paid
     * invoice unsettled. Verification authorises itself: an authority we did not issue
     * is rejected, and one we did can only be settled once.
     */
    public function callback(Request $request): RedirectResponse
    {
        /** @var string $authority */
        $authority = (string) ($request->query('Authority') ?? $request->query('authority') ?? '');

        if ($authority === '') {
            return redirect()->route('billing.index')->with('error', 'پاسخ درگاه پرداخت نامعتبر بود.');
        }

        try {
            $attempt = $this->billing->verifyCallback($authority, $request->query());
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
