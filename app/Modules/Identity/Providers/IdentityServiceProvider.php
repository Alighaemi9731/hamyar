<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Models\Activity;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\ActivityPolicy;
use App\Modules\Identity\Policies\UserPolicy;
use App\Support\Audit\AuditSubjects;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Identity module.
 *
 * Spec: docs/specs/identity.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class IdentityServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);

        // Identity has audited its own model since Phase 2; this only gives the
        // filter a Persian name for it.
        $this->app->make(AuditSubjects::class)->register(
            'user', User::class, 'کاربر', 50,
            static fn (int $id): ?string => User::query()->find($id)?->name,
        );

        $this->registerOwnerOverride();
    }

    /**
     * Abilities the Owner override must NOT satisfy.
     *
     * These encode structural invariants rather than permissions. "Nobody edits their
     * own roles" protects an Owner from removing their own Owner role as much as it
     * stops a Manager from granting themselves one — so a blanket override would
     * quietly reintroduce the lockout the rule exists to prevent.
     *
     * @var list<string>
     */
    private const OWNER_OVERRIDE_EXCEPTIONS = [
        'assignRoles',
        'deactivate',
    ];

    /**
     * The Owner can do everything in their own shop.
     *
     * A `Gate::before` rather than a permission list, so a capability shipped in a
     * later phase is immediately available to the Owner without a data migration
     * across every tenant.
     *
     * Returning `null` (not `false`) when the override does not apply is essential:
     * `false` short-circuits the gate and would deny the ability outright instead of
     * letting the real policy decide.
     */
    private function registerOwnerOverride(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (in_array($ability, self::OWNER_OVERRIDE_EXCEPTIONS, true)) {
                return null;
            }

            if ($user instanceof User && $user->hasRole('Owner')) {
                return true;
            }

            return null;
        });
    }
}
