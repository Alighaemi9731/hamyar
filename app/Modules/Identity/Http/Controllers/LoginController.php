<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\AccountLookup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Per-tenant login. Reached only through the `tenant` middleware, so the context —
 * and therefore the RLS policy on `users` — is already pinned to this shop.
 *
 * That is what makes the credential check safe without any extra filtering: a user
 * row from another shop is not merely filtered out of the query, it is invisible to
 * the database session.
 */
final class LoginController extends Controller
{
    /**
     * Blade, not Inertia.
     *
     * The public surfaces — landing, legal, sign-up and this — share one design language
     * that lives in a Blade stylesheet (ADR 0016). Rendered through React this page
     * inherited the *application's* look instead, which is why it did not match the page
     * the visitor arrived from.
     */
    /**
     * One login page, for every shop.
     *
     * It used to be reached on the shop's own hostname and therefore knew, and showed,
     * which shop you were signing into. ADR 0017 removed per-shop addresses, so there is
     * nothing to name here: the tenant is a RESULT of authenticating, not context the
     * page already has.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AccountLookup $accounts, TenantContext $context): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        /*
        | Not `Auth::attempt()`, and it is not a preference.
        |
        | `attempt()` resolves the user through the tenant-scoped provider. With one
        | address for every shop there is no tenant pinned when somebody types their
        | number in — the tenant is what authenticating *produces* — so RLS returns zero
        | rows and every credential is reported wrong. It would not fail loudly; it would
        | simply never let anybody in.
        |
        | AccountLookup crosses tenants deliberately and says why (golden rule 1 allows
        | `withoutTenancy()` in Platform, with the reason written down).
        */
        $credentials = $request->credentials();

        $user = $accounts->forCredentials(
            (string) ($credentials['mobile'] ?? ''),
            (string) ($credentials['password'] ?? ''),
        );

        if (! $user instanceof User) {
            $request->recordFailedAttempt();

            return back()
                ->withInput($request->only('mobile'))
                // One message for both "no such number" and "wrong password": telling
                // them apart lets anyone enumerate which numbers have accounts.
                ->withErrors(['mobile' => 'شماره موبایل یا رمز عبور درست نیست.']);
        }

        if (! $user->is_active) {
            return back()
                ->withInput($request->only('mobile'))
                ->withErrors(['mobile' => 'حساب کاربری شما غیرفعال شده است. با مدیر فروشگاه تماس بگیرید.']);
        }

        $request->clearRateLimit();

        /*
        | Pin the tenant, from the authenticated user's own record and from nowhere else.
        |
        | This single line is what replaces the hostname. ResolveTenant reads it on every
        | later request, so the rule that keeps the boundary sound is: **nothing outside
        | this flow may ever write `tenant_id` into the session.** It is not derived from
        | a parameter, a header, or anything the visitor can author.
        */
        $tenant = $user->tenant;

        if (! $tenant instanceof Tenant) {
            // A user row whose tenant is gone. Not reachable through the application —
            // the foreign key cascades — but reachable through a bad restore or manual
            // surgery, and the alternative to checking is pinning null and letting RLS
            // deny everything with no explanation.
            return back()
                ->withInput($request->only('mobile'))
                ->withErrors(['mobile' => 'حساب کاربری شما به فروشگاهی متصل نیست. با پشتیبانی تماس بگیرید.']);
        }

        if (! $tenant->isUsable()) {
            return back()
                ->withInput($request->only('mobile'))
                ->withErrors(['mobile' => 'دسترسی به این فروشگاه موقتاً غیرفعال است.']);
        }

        $request->session()->put('tenant_id', $tenant->getKey());
        $context->set($tenant);

        // 2FA: stop short of an authenticated session. The password is proven, the
        // second factor is not, so nothing is logged in yet — the pending id is parked
        // in the session and redeemed by TwoFactorController::verify().
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put(TwoFactorController::PENDING_SESSION_KEY, $user->getKey());
            $request->session()->put('auth.two_factor.remember', $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));

        // Rotates the session id, so a session fixed before login is worthless. Session
        // DATA survives, which is what keeps the tenant_id just written.
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
