<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TOTP enrolment and the login challenge.
 *
 * The challenge is stateful by session: `LoginController` stops short of
 * authenticating when a user has 2FA and parks the id in the session instead. Nothing
 * is authenticated until a valid code arrives.
 */
final class TwoFactorController extends Controller
{
    public const PENDING_SESSION_KEY = 'auth.two_factor.pending_user_id';

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TwoFactorService $service) {}

    /* ------------------------------------------------------------ enrolment -- */

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/two-factor', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'recoveryCodesRemaining' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }

    /**
     * Begin enrolment. Re-entering the password first stops someone who walks up to
     * an unlocked screen from binding their own authenticator to the account.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->string('password')->value(), $user->password)) {
            return back()->withErrors(['password' => 'رمز عبور درست نیست.']);
        }

        $setup = $this->service->begin($user);

        return back()->with('twoFactorSetup', $setup);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        $codes = $this->service->confirm($user, (string) $request->string('code'));

        if ($codes === null) {
            return back()->withErrors(['code' => 'کد واردشده درست نیست.']);
        }

        // Shown once. If the user loses them they regenerate; we never store a
        // retrievable copy, which is what makes them a real second factor.
        return back()->with([
            'success' => 'ورود دومرحله‌ای فعال شد.',
            'recoveryCodes' => $codes,
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->string('password')->value(), $user->password)) {
            return back()->withErrors(['password' => 'رمز عبور درست نیست.']);
        }

        $this->service->disable($user);

        return back()->with('success', 'ورود دومرحله‌ای غیرفعال شد.');
    }

    /* ------------------------------------------------------------ challenge -- */

    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has(self::PENDING_SESSION_KEY)) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required_without:recovery_code', 'nullable', 'string'],
            'recovery_code' => ['required_without:code', 'nullable', 'string'],
        ]);

        $pendingId = $request->session()->get(self::PENDING_SESSION_KEY);

        if (! is_int($pendingId)) {
            return redirect()->route('login');
        }

        $throttleKey = 'two-factor|'.$pendingId.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return back()->withErrors([
                'code' => 'تلاش‌های ناموفق زیاد بوده است. کمی بعد دوباره تلاش کنید.',
            ]);
        }

        // Resolved under the tenant context the middleware already pinned, so a
        // pending id from another shop cannot be redeemed here.
        $user = User::query()->find($pendingId);

        if (! $user instanceof User) {
            $request->session()->forget(self::PENDING_SESSION_KEY);

            return redirect()->route('login');
        }

        $code = (string) $request->string('code');
        $recovery = (string) $request->string('recovery_code');

        $passed = $code !== ''
            ? $this->service->verify($user, $code)
            : ($recovery !== '' && $this->service->consumeRecoveryCode($user, $recovery));

        if (! $passed) {
            RateLimiter::hit($throttleKey, 60);

            return back()->withErrors(['code' => 'کد واردشده درست نیست.']);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->forget(self::PENDING_SESSION_KEY);

        Auth::login($user, (bool) $request->session()->pull('auth.two_factor.remember', false));

        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return redirect()->intended(route('dashboard'));
    }
}
