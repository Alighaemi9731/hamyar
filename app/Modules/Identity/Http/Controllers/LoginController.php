<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Models\User;
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
    public function create(TenantContext $context): View
    {
        /*
        | The shop's name is passed because the page must SAY which shop you are signing
        | into, and TenantIsolationTest asserts exactly that: alpha's login page shows
        | "Alpha" and beta's shows "Beta".
        |
        | It is an isolation property, not decoration. This page is reached by hostname,
        | and a page that looks identical whichever shop it is serving gives a person no
        | way to notice they are about to type their password into the wrong one.
        */
        return view('auth.login', [
            'shopName' => $context->tenant()?->name,
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        if (! Auth::attempt($request->credentials(), $request->boolean('remember'))) {
            $request->recordFailedAttempt();

            return back()
                ->withInput($request->only('mobile'))
                // One message for both "no such user" and "wrong password": telling
                // them apart lets anyone enumerate which staff work at a shop.
                ->withErrors(['mobile' => 'شماره موبایل یا رمز عبور درست نیست.']);
        }

        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            return back()->withErrors(['mobile' => 'حساب کاربری شما غیرفعال شده است. با مدیر فروشگاه تماس بگیرید.']);
        }

        $request->clearRateLimit();

        // 2FA: stop short of an authenticated session. The password is proven, the
        // second factor is not, so nothing is logged in yet — the pending id is
        // parked in the session and redeemed by TwoFactorController::verify().
        if ($user instanceof User && $user->hasTwoFactorEnabled()) {
            $pendingId = $user->getKey();

            Auth::logout();

            $request->session()->put(TwoFactorController::PENDING_SESSION_KEY, $pendingId);
            $request->session()->put('auth.two_factor.remember', $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        // Rotates the session id, so a session fixed before login is worthless.
        $request->session()->regenerate();

        $user?->forceFill([
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
