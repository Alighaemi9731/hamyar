<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\PasswordResetService;
use App\Support\Digits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-tenant password reset.
 *
 * Delivery is a stub until the Messaging module lands in Phase 8: the link is logged
 * rather than sent. That is deliberate and visible — a silently-swallowed reset link
 * is worse than an obviously unfinished one.
 */
final class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $service) {}

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

        $token = $this->service->issue($identifier);

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

        $ok = $this->service->reset(
            Digits::toLatin(trim($validated['identifier'])),
            $validated['token'],
            $validated['password'],
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
