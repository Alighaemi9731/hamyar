<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\PasswordResetService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\AccountLookup;
use App\Support\Digits;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-tenant password reset, reached with no tenant in the session.
 *
 * ## Why the shop is resolved here rather than by middleware
 *
 * [ADR 0017](../../../../../docs/adr/0017-single-host-app.md) made the tenant a
 * property of the session, established at login. Somebody who has forgotten their
 * password has no session — that is the whole situation — so these four routes cannot
 * sit behind `tenant`, which would redirect them to the login page they are trying to
 * get back to.
 *
 * The mobile number resolves the shop instead (`AccountLookup::tenantForMobile()`,
 * which lives in Platform because golden rule 1 permits `withoutTenancy()` only there),
 * and the token work runs inside `TenantContext::runFor()`. `PasswordResetService` is
 * unchanged and still requires a pinned tenant — `issue()` and `reset()` both call
 * `idOrFail()`, so a missed context is a loud failure rather than a silent cross-shop
 * reset.
 *
 * ## The identical answer survives the change
 *
 * An unresolved number takes the same path as a resolved one that turns out to have no
 * active account: no token, and the same flash. Anything else would turn this form into
 * an oracle for "does this number have an account?" — worse now than before, because
 * the answer would be about the whole platform rather than about one shop.
 *
 * Delivery is a stub until the Messaging module lands in Phase 8: the link is logged
 * rather than sent. That is deliberate and visible — a silently-swallowed reset link
 * is worse than an obviously unfinished one.
 */
final class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $service,
        private readonly AccountLookup $accounts,
        private readonly TenantContext $context,
    ) {}

    public function create(): Response
    {
        return Inertia::render('auth/forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:190'],
        ]);

        $identifier = Digits::toLatin(trim($validated['identifier']));

        $tenant = $this->accounts->tenantForMobile($identifier);

        $token = $tenant instanceof Tenant
            ? $this->context->runFor($tenant, fn (): ?string => $this->service->issue($identifier))
            : null;

        if ($token !== null) {
            // Phase 8 replaces this with a pattern SMS.
            Log::info('Password reset link issued.', [
                'identifier' => $identifier,
                'url' => route('password.edit', ['token' => $token, 'identifier' => $identifier]),
            ]);
        }

        // ALWAYS the same response, whether or not the account exists. Anything else
        // turns this form into an oracle for "does this person work at this shop?".
        return back()->with(
            'success',
            'اگر این شماره در این فروشگاه ثبت شده باشد، لینک بازیابی برایتان ارسال می‌شود.'
        );
    }

    public function edit(Request $request): Response
    {
        return Inertia::render('auth/reset-password', [
            'token' => (string) $request->query('token'),
            'identifier' => (string) $request->query('identifier'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'identifier' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $identifier = Digits::toLatin(trim($validated['identifier']));

        $tenant = $this->accounts->tenantForMobile($identifier);

        // No shop for this number means no token was ever issued for it either, so the
        // answer is the same one an expired or forged token gets.
        $ok = $tenant instanceof Tenant && $this->context->runFor(
            $tenant,
            fn (): bool => $this->service->reset(
                $identifier,
                $validated['token'],
                $validated['password'],
            ),
        );

        if (! $ok) {
            return back()->withErrors([
                'token' => 'این لینک بازیابی معتبر نیست یا منقضی شده است. لطفاً دوباره درخواست دهید.',
            ]);
        }

        return redirect()
            ->route('login')
            ->with('success', 'رمز عبور شما تغییر کرد. حالا وارد شوید.');
    }
}
