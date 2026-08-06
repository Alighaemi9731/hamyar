<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props every Inertia page receives.
 *
 * Keep this in sync with `resources/js/types/index.d.ts` — pages are written against
 * that type, so an untyped prop added here is invisible to the frontend.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'auth' => [
                'user' => fn (): ?array => $this->user($request),
            ],

            // Phase 1 replaces this with the resolved TenantContext. Until then every
            // page renders in the central (no-tenant) context.
            'tenant' => null,

            // Phase 2 resolves these from the tenant's plan via Pennant. Hiding nav
            // with them is convenience only — routes are guarded independently by
            // EnsureModuleEnabled (golden rule 7).
            'features' => (object) [],

            'flash' => [
                'success' => fn (): ?string => $this->flash($request, 'success'),
                'error' => fn (): ?string => $this->flash($request, 'error'),
                'warning' => fn (): ?string => $this->flash($request, 'warning'),
                'info' => fn (): ?string => $this->flash($request, 'info'),
            ],

            'location' => $request->getPathInfo(),
        ];
    }

    /**
     * Flash values are session data and therefore `mixed`. Narrowing here keeps the
     * shared-prop contract honest instead of shipping `mixed` to the TypeScript side.
     */
    private function flash(Request $request, string $key): ?string
    {
        $value = $request->session()->get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function user(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => null,
            // Phase 1 fills these from spatie/laravel-permission (teams = tenant_id).
            'permissions' => [],
            'roles' => [],
        ];
    }
}
