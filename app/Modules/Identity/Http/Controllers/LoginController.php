<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

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
    public function create(): Response
    {
        return Inertia::render('auth/login');
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
