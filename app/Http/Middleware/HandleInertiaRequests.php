<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Announcement;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Support\PlanCatalogue;
use App\Support\Tenancy\TenantContext;
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

            // Null on central routes (onboarding, billing, the platform panel) — the
            // frontend uses that to tell "no shop yet" from "inside a shop".
            'tenant' => fn (): ?array => $this->tenant(),

            // Resolved from the tenant's plan + add-ons. Hiding nav with these is
            // convenience only — the route is guarded independently by
            // EnsureModuleEnabled (golden rule 7).
            //
            // Staff-only, like `announcements` below: see `isStaff()`.
            'features' => fn (): array => $this->isStaff($request) ? $this->features() : [],

            'flash' => [
                'success' => fn (): ?string => $this->flash($request, 'success'),
                'error' => fn (): ?string => $this->flash($request, 'error'),
                'warning' => fn (): ?string => $this->flash($request, 'warning'),
                'info' => fn (): ?string => $this->flash($request, 'info'),
            ],

            // Platform notices. A lazy closure, so the query only runs for pages that
            // actually read it — this is on every request in the app shell, and an
            // eager query here would tax the POS screen for a banner that is usually
            // absent.
            'announcements' => fn (): array => $this->isStaff($request) ? $this->announcements() : [],

            'location' => $request->getPathInfo(),
        ];
    }

    /**
     * Whether this request is a signed-in member of the shop's staff.
     *
     * Not every Inertia page in this app is behind `auth`. The public invoice view
     * (`/i/{invoice}`) is opened by a customer's phone from a QR code on a receipt, and
     * it renders through the same middleware — so anything shared unconditionally here
     * is shared with a stranger holding a piece of paper.
     *
     * Two props are genuinely staff-only and are gated on this:
     *
     * - `announcements` are platform notices written for shop owners. "Your subscription
     *   expires in three days" on a customer's receipt page is somebody else's business.
     * - `features` describes which modules the shop pays for, which is commercial
     *   information about the shop rather than about the invoice.
     *
     * `tenant` deliberately stays: it is the shop's name and its digit/currency display
     * preference, both of which the public page needs to render money the way the
     * receipt in the customer's hand does.
     */
    private function isStaff(Request $request): bool
    {
        return $request->user() instanceof User;
    }

    /**
     * `module:<code> => bool` for every sellable module.
     *
     * Every module is listed explicitly, including the disabled ones, so the frontend
     * can distinguish "not in your plan" (false) from "unknown key" (absent) — the
     * first is an upsell, the second is a bug.
     *
     * @return array<string, bool>
     */
    private function features(): array
    {
        if (! app(TenantContext::class)->has()) {
            return [];
        }

        $granted = app(SubscriptionResolver::class)->grantedModuleCodes();

        $features = [];

        foreach (PlanCatalogue::modules() as $module) {
            $features['module:'.$module['code']] = in_array($module['code'], $granted, true);
        }

        return $features;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tenant(): ?array
    {
        $tenant = app(TenantContext::class)->tenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return [
            'id' => $tenant->getKey(),
            'name' => $tenant->name,
            'subdomain' => $tenant->slug,
            'settings' => [
                'currency_display' => $tenant->setting('currency_display', 'toman'),
                'digits' => $tenant->setting('digits', 'fa'),
            ],
        ];
    }

    /**
     * Flash values are session data and therefore `mixed`. Narrowing here keeps the
     * shared-prop contract honest instead of shipping `mixed` to the TypeScript side.
     */
    /**
     * Live platform notices for the current shop.
     *
     * `announcements` is a central table read by every tenant, so it needs no tenant
     * context — but the scope still filters by tenant id, because a row may target one
     * shop. On a central route there is no tenant, and only the global notices apply.
     *
     * @return list<array{id: int, title: string, body: string, level: string}>
     */
    private function announcements(): array
    {
        $rows = Announcement::query()
            ->visibleTo(app(TenantContext::class)->id())
            ->limit(3)
            ->get(['id', 'title', 'body', 'level']);

        return array_values($rows->map(static fn (Announcement $a): array => [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'level' => $a->level,
        ])->all());
    }

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

        // Guard on our concrete model rather than the Authenticatable contract: the
        // contract has none of these attributes, and the platform guard resolves a
        // different class entirely.
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            // Flattened for the UI. Hiding a control with these is convenience only —
            // the route and the policy are the actual authorization (golden rule 7).
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'roles' => $user->getRoleNames()->values()->all(),
        ];
    }
}
