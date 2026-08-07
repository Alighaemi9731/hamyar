<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Where am I logged in?" — and the ability to end those sessions.
 *
 * This is why sessions use the database driver rather than Redis: enumerating a
 * user's active sessions is the entire feature, and the Redis driver cannot do it
 * without a parallel index we would have to keep correct ourselves.
 */
final class SessionController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/sessions', [
            'sessions' => $this->sessionsFor($request),
        ]);
    }

    /**
     * End every session except this one.
     *
     * Password re-entry is required: this is the button someone presses when they
     * think their account is compromised, and it must not be usable by whoever is
     * sitting at an unlocked screen.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();

        if (! $user instanceof User || ! Hash::check($request->string('password')->value(), $user->password)) {
            return back()->withErrors(['password' => 'رمز عبور درست نیست.']);
        }

        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with('success', 'همه نشست‌های دیگر بسته شدند.');
    }

    public function destroy(Request $request, string $session): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return back();
        }

        // Scoped by user_id, so an id guessed from elsewhere cannot end a stranger's
        // session.
        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', $session)
            ->delete();

        return back()->with('success', 'نشست بسته شد.');
    }

    /**
     * @return list<array{id: string, is_current: bool, ip: string|null, agent: string, last_active_at: string}>
     */
    private function sessionsFor(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        $rows = DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(function (object $row) use ($request): array {
                $id = (string) $row->id;

                return [
                    'id' => $id,
                    'is_current' => $id === $request->session()->getId(),
                    'ip' => $row->ip_address === null ? null : (string) $row->ip_address,
                    'agent' => $this->describeAgent((string) ($row->user_agent ?? '')),
                    'last_active_at' => CarbonImmutable::createFromTimestampUTC((int) $row->last_activity)
                        ->toIso8601String(),
                ];
            })
            ->all();

        // array_values(), not Collection::values(): only the former narrows to a
        // `list` for static analysis.
        return array_values($rows);
    }

    /**
     * A short, human description. Full user-agent strings are noise to a shop owner
     * trying to answer "is that me?".
     */
    private function describeAgent(string $agent): string
    {
        $platform = match (true) {
            str_contains($agent, 'Android') => 'اندروید',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'ویندوز',
            str_contains($agent, 'Mac OS') => 'مک',
            str_contains($agent, 'Linux') => 'لینوکس',
            default => 'نامشخص',
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Safari') => 'Safari',
            default => '—',
        };

        return $platform.' · '.$browser;
    }
}
