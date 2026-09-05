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
use Illuminate\View\View;

/**
 * The invited user's side: open the link, choose a password, join the shop.
 *
 * ## The token is what says which shop this is
 *
 * The link used to live on the shop's own hostname and `tenant` pinned the context from
 * it. [ADR 0017](../../../../../docs/adr/0017-single-host-app.md) removed per-shop
 * addresses, and an invited person has no session by definition — so `tenant` would
 * bounce them to /login, which is the one page an invitation is not.
 *
 * So the token carries the tenant, as a PATH parameter that `tenant.public:invitation`
 * reads before this controller runs. By the time either method is entered the shop that
 * issued the invitation is pinned, and the lookup below is confined to it exactly as it
 * always was. An unknown token never gets here at all — the middleware 404s it.
 *
 * The token stays a route argument rather than being re-read from the request, so there
 * is exactly one value in play: the one the middleware resolved the tenant from.
 */
final class InvitationController extends Controller
{
    /**
     * Blade, not Inertia.
     *
     * The person opening this link has never seen the application and has no session; the
     * first thing they meet is the auth flow's own design language (ADR 0021). Rendering
     * it through React meant fetching the whole application bundle to be shown two
     * password fields, in a skin they would not see again until they had used them.
     *
     * The token is passed on so the form can post back to its own path — see the view.
     */
    public function accept(string $token): View|RedirectResponse
    {
        $invitation = $this->resolve($token);

        if (! $invitation instanceof Invitation) {
            return redirect()->route('login')->withErrors([
                'mobile' => 'این دعوت‌نامه معتبر نیست یا منقضی شده است.',
            ]);
        }

        return view('auth.accept-invitation', [
            'token' => $token,
            'name' => $invitation->name,
            'mobile' => $invitation->mobile,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $invitation = $this->resolve($token);

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

        /*
        | The SECOND — and only other — place that writes `session('tenant_id')`.
        |
        | ResolveTenant's docblock states the rule this depends on: nothing outside the
        | login flow may write that key, because it is the whole of the tenant boundary
        | since ADR 0017. Accepting an invitation *is* a login flow — it ends with an
        | authenticated session — so it has to write it, and it is written down here and
        | there rather than left as an exception somebody has to notice.
        |
        | It obeys the same constraint LoginController::store() does: the value comes
        | from a user row this request just created inside the tenant the TOKEN
        | resolved, never from a parameter, a header or anything the visitor authored.
        | Without it the redirect below lands on /dashboard with no pinned shop and
        | bounces straight back to /login, having created the account.
        */
        $request->session()->put('tenant_id', $user->tenant_id);

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
