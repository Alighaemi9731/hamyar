<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The receiving end of an impersonation hand-off.
 *
 * Reached only through a signed, two-minute URL minted by
 * {@see \App\Modules\Platform\Services\ImpersonationService}, which has already written
 * the audit record. The `signed` middleware is the entire authorisation: nobody is logged
 * in yet when this runs, so there is no session to check.
 *
 * ## This is the third deliberate writer of `session('tenant_id')`
 *
 * [ADR 0017](../../../../docs/adr/0017-single-host-app.md) made the tenant a property of
 * the session, and `ResolveTenant` is the only reader. Three flows write it, and each one
 * derives the value from a server-side record rather than from the request:
 *
 * · `LoginController::store()` — from the authenticated user's own `tenant_id`.
 * · `InvitationController` — from the invitation row a token hash resolved to.
 * · here — from the impersonated user's own row, reached only through a signed link
 *   that was audited into that shop's activity log before it existed.
 *
 * That property, not the count, is what keeps the boundary sound: there is no request
 * shape that makes any of the three adopt a tenant the caller nominated. A fourth writer
 * without it would be the breach.
 *
 * ## Why the tenant has to be written HERE rather than left to the middleware
 *
 * This route used to sit behind `tenant`, back when the link landed on the shop's own
 * hostname and the middleware could read the shop off it. It cannot be there any more:
 * with the tenant coming from a session that does not exist yet, `ResolveTenant` answers
 * a 302 to /login *before* `signed` ever runs — so a forged link produced a redirect
 * instead of a 403, and a valid one never reached this method at all.
 */
final class ImpersonationController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function start(Request $request, int $user): RedirectResponse
    {
        $target = $this->targetAcrossTenants($user);

        if (! $target instanceof User) {
            abort(404);
        }

        // Regenerate BEFORE logging in. Session fixation is the reason to regenerate at
        // all, and doing it after `login()` throws away the id the guard just wrote.
        $request->session()->regenerate();

        /*
        | Pin the shop, from the target's own row and from nowhere else.
        |
        | The signed link carries a user id; it does not carry — and must never carry — a
        | tenant. Reading the shop off the row the id resolved to is what stops the URL
        | becoming a way to nominate one.
        |
        | Written before `login()` for the same reason the login flow writes it before
        | `Auth::login()`: the guard's own session write must not be the thing that
        | decides ordering, and a session carrying a user with no tenant is exactly the
        | state ResolveTenant flushes back to /login.
        */
        $request->session()->put('tenant_id', $target->tenant_id);

        Auth::guard('web')->login($target);

        // Marks the session so the app shell can show a persistent "you are impersonating"
        // banner. Without it, a staff member forgets, and the next thing they do looks to
        // everyone like the owner did it.
        $request->session()->put('impersonating', true);

        return redirect()->route('dashboard')
            ->with('warning', 'شما به عنوان مالک این فروشگاه وارد شده‌اید. این نشست ثبت شده است.');
    }

    /**
     * The account this link was minted for, found across every shop.
     *
     * ## Why it reads across tenants, and why that is allowed
     *
     * Nobody is signed in when a hand-off link is followed, so nothing is pinned and the
     * target's own row is the only thing that can say which shop this is. Golden rule 1
     * permits `withoutTenancy()` inside the Platform module with the reason written down;
     * this is the reason, and it is the same one
     * {@see \App\Modules\Platform\Services\AccountLookup} carries for the login form.
     *
     * `runAsPlatform()` as well as `withoutTenancy()`, because the two layers of ADR 0002
     * fail differently: `withoutTenancy()` lifts only the Eloquent scope, while the
     * Postgres policy on `users` keeps denying every row for as long as `app.tenant_id`
     * is unset. Without the flag this resolves to null and every valid signed link 404s —
     * the defect commit 2e2951c fixed for the login form, which is why `users` opted into
     * the platform flag in the first place.
     *
     * Widening the lookup does not widen who may use it. The signature is still the
     * authorisation: it is minted for one user id, expires in two minutes, and the audit
     * record was written into that shop's log before the link existed.
     */
    private function targetAcrossTenants(int $id): ?User
    {
        $found = $this->context->runAsPlatform(
            static fn (): ?Model => User::withoutTenancy()
                ->where('is_active', true)
                ->whereKey($id)
                ->first(),
        );

        return $found instanceof User ? $found : null;
    }
}
