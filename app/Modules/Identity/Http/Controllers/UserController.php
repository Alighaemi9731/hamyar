<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalogue;
use App\Support\Digits;
use App\Support\Quota\QuotaGuard;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Shop staff management: who is here, what they may do, and inviting more.
 *
 * Every action is authorized by a policy (`users.*` permissions) rather than by a
 * role check, so a shop that has customised its roles still gets the behaviour it
 * configured.
 */
final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        return Inertia::render('settings/users', [
            'users' => User::query()
                ->with('roles:id,name,name_fa')
                ->orderBy('name')
                ->get(['id', 'name', 'mobile', 'email', 'is_active', 'last_login_at'])
                ->map(fn (User $user): array => [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'mobile' => $user->mobile,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'roles' => $user->roles->pluck('name_fa', 'name')->all(),
                    'is_self' => $user->getKey() === $request->user()?->getKey(),
                ])
                ->values()
                ->all(),

            'invitations' => Invitation::query()
                ->latest()
                ->get()
                ->map(fn (Invitation $invitation): array => [
                    'id' => $invitation->getKey(),
                    'name' => $invitation->name,
                    'mobile' => $invitation->mobile,
                    'role' => $invitation->role,
                    'status' => $invitation->status(),
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ])
                ->values()
                ->all(), // @phpstan-ignore-line

            'roles' => Role::query()
                ->orderBy('id')
                ->get(['name', 'name_fa'])
                ->map(fn (Role $role): array => [
                    'name' => $role->getAttribute('name'),
                    'label' => $role->getAttribute('name_fa') ?? $role->getAttribute('name'),
                ])
                ->all(), // @phpstan-ignore-line
        ]);
    }

    public function invite(Request $request, QuotaGuard $quota, ConnectionInterface $connection): RedirectResponse
    {
        $this->authorize('invite', User::class);

        $request->merge(['mobile' => Digits::toLatin(trim($request->string('mobile')->value()))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'mobile' => ['required', 'string', 'regex:/^09\d{9}$/', Rule::unique('users', 'mobile')],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'role' => ['required', 'string', Rule::in(array_keys(PermissionCatalogue::roles()))],
        ], [
            'mobile.unique' => 'این شماره قبلاً در فروشگاه ثبت شده است.',
            'mobile.regex' => 'شماره موبایل باید با ۰۹ شروع شود و ۱۱ رقم باشد.',
        ]);

        ['token' => $token, 'hash' => $hash] = Invitation::mintToken();

        /*
        | The seat is reserved HERE, at invite, not at accept.
        |
        | `identity.users` measures active users plus pending invitations, so the check
        | and the row have to happen together — otherwise two managers inviting at the
        | same last seat both measure "one free" and both send. The advisory lock inside
        | `assertCapacity()` is what serialises them, and it is transaction-scoped, which
        | is why this needs one.
        |
        | Accepting deliberately does NOT re-check: the invitation being accepted is
        | already inside the measure, so a second check would refuse the very seat it had
        | made room for.
        */
        /** @var Invitation $invitation */
        $invitation = $connection->transaction(function () use ($request, $validated, $hash, $quota): Invitation {
            $quota->consume('identity.users');

            /** @var Invitation $invitation */
            $invitation = Invitation::query()->create([
                'invited_by_id' => $request->user()?->getKey(),
                'name' => $validated['name'],
                'mobile' => $validated['mobile'],
                'email' => $validated['email'] ?? null,
                'role' => $validated['role'],
                'token_hash' => $hash,
                'expires_at' => now()->addDays(7),
            ]);

            return $invitation;
        });

        // Phase 8 replaces this with a pattern SMS.
        Log::info('Invitation issued.', [
            'invitation_id' => $invitation->getKey(),
            'url' => route('invitations.accept', ['token' => $token]),
        ]);

        return back()->with('success', 'دعوت‌نامه ساخته شد.');
    }

    public function revokeInvitation(Invitation $invitation): RedirectResponse
    {
        $this->authorize('invite', User::class);

        $invitation->forceFill(['revoked_at' => now()])->save();

        return back()->with('success', 'دعوت‌نامه لغو شد.');
    }

    public function updateRoles(Request $request, User $user): RedirectResponse
    {
        $this->authorize('assignRoles', $user);

        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        // A shop that removes the last Owner locks itself out of its own billing and
        // user management with no way back except support.
        if ($this->wouldLeaveNoOwner($user, $validated['roles'])) {
            return back()->withErrors([
                'roles' => 'فروشگاه باید حداقل یک «مالک» داشته باشد.',
            ]);
        }

        $user->syncRoles($validated['roles']);

        return back()->with('success', 'نقش‌ها به‌روزرسانی شد.');
    }

    public function toggleActive(Request $request, User $user, QuotaGuard $quota, ConnectionInterface $connection): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        if ($user->getKey() === $request->user()?->getKey()) {
            return back()->withErrors(['user' => 'نمی‌توانید حساب خودتان را غیرفعال کنید.']);
        }

        $activating = ! $user->is_active;

        if (! $activating && $this->wouldLeaveNoOwner($user, [])) {
            return back()->withErrors(['user' => 'فروشگاه باید حداقل یک «مالک» فعال داشته باشد.']);
        }

        /*
        | Re-activating takes a seat back, so it is checked; deactivating gives one up and
        | is always free.
        |
        | Without the check here the cap has a trivial back door: deactivate, invite
        | somebody in the freed seat, re-activate. Three ordinary clicks, each of them
        | individually reasonable, and the shop is a seat over its plan for ever.
        */
        $connection->transaction(function () use ($user, $activating, $quota): void {
            if ($activating) {
                $quota->consume('identity.users');
            }

            $user->forceFill(['is_active' => $activating])->save();
        });

        return back()->with('success', $activating ? 'کاربر فعال شد.' : 'کاربر غیرفعال شد.');
    }

    /**
     * @param  list<string>  $newRoles
     */
    private function wouldLeaveNoOwner(User $user, array $newRoles): bool
    {
        if (! $user->hasRole('Owner') || in_array('Owner', $newRoles, true)) {
            return false;
        }

        $otherActiveOwners = User::query()
            ->whereKeyNot($user->getKey())
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'Owner'))
            ->count();

        return $otherActiveOwners === 0;
    }
}
