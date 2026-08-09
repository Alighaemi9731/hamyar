<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
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
 */
final class ImpersonationController extends Controller
{
    public function start(Request $request, int $user): RedirectResponse
    {
        // Resolved inside the tenant's own scope. A signed link forged for a user id in
        // ANOTHER shop resolves to nothing here, because the global scope and RLS both
        // refuse it — the signature proves the link came from us, not that the id belongs
        // to this hostname.
        $target = User::query()->where('is_active', true)->find($user);

        if (! $target instanceof User) {
            abort(404);
        }

        // Regenerate BEFORE logging in. Session fixation is the reason to regenerate at
        // all, and doing it after `login()` throws away the id the guard just wrote.
        $request->session()->regenerate();

        Auth::guard('web')->login($target);

        // Marks the session so the app shell can show a persistent "you are impersonating"
        // banner. Without it, a staff member forgets, and the next thing they do looks to
        // everyone like the owner did it.
        $request->session()->put('impersonating', true);

        return redirect()->route('dashboard')
            ->with('warning', 'شما به عنوان مالک این فروشگاه وارد شده‌اید. این نشست ثبت شده است.');
    }
}
