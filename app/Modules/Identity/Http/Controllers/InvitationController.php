<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The invited user's side: open the link, choose a password, join the shop.
 *
 * Unauthenticated but tenant-scoped — the link lives on the shop's own hostname, so
 * the middleware has already pinned the context and the token lookup is automatically
 * confined to that shop.
 */
final class InvitationController extends Controller
{
    public function accept(Request $request): Response|RedirectResponse
    {
        $invitation = $this->resolve((string) $request->query('token'));

        if (! $invitation instanceof Invitation) {
            return redirect()->route('login')->withErrors([
                'mobile' => 'این دعوت‌نامه معتبر نیست یا منقضی شده است.',
            ]);
        }

        return Inertia::render('auth/accept-invitation', [
            'token' => (string) $request->query('token'),
            'name' => $invitation->name,
            'mobile' => $invitation->mobile,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $invitation = $this->resolve($validated['token']);

        if (! $invitation instanceof Invitation) {
            return back()->withErrors([
                'token' => 'این دعوت‌نامه معتبر نیست یا منقضی شده است.',
            ]);
        }

        $user = DB::transaction(function () use ($invitation, $validated): User {
            $user = User::query()->create([
                'name' => $invitation->name,
                'mobile' => $invitation->mobile,
                'email' => $invitation->email,
                'password' => $validated['password'],
                'is_active' => true,
            ]);

            $user->assignRole($invitation->role);

            // Marked accepted inside the same transaction, so a crash cannot leave a
            // consumed invitation that still looks redeemable.
            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('dashboard')
            ->with('success', 'خوش آمدید! حساب شما ساخته شد.');
    }

    private function resolve(string $token): ?Invitation
    {
        if ($token === '') {
            return null;
        }

        // Looked up by hash: the plaintext token exists only in the link.
        $invitation = Invitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $invitation instanceof Invitation && $invitation->isPending() ? $invitation : null;
    }
}
