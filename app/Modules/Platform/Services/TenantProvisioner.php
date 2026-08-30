<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalogue;
use App\Modules\Platform\Events\TenantProvisioned;
use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Creates a working shop from nothing: tenant, hostname, roles, owner.
 *
 * The whole thing is one transaction. A half-provisioned tenant — a domain that
 * resolves to a shop with no roles, or an owner with no permissions — is a support
 * ticket that starts with the customer locked out of the product they just paid for.
 */
final class TenantProvisioner
{
    /**
     * Subdomains that must never belong to a shop.
     *
     * `www`, `app` and friends are ours. The rest are reserved because a shop at
     * `admin.hamyar.ir` or `support.hamyar.ir` could impersonate us convincingly
     * enough to phish another shop's owner.
     *
     * @var list<string>
     */
    public const RESERVED_SUBDOMAINS = [
        'www', 'api', 'app', 'admin', 'administrator', 'mail', 'smtp', 'imap',
        'ftp', 'static', 'assets', 'cdn', 'img', 'media', 'files', 'download',
        'billing', 'pay', 'payment', 'checkout', 'account', 'accounts',
        'support', 'help', 'docs', 'status', 'blog', 'shop', 'store',
        'panel', 'dashboard', 'console', 'my', 'secure', 'login', 'signup',
        'register', 'auth', 'sso', 'test', 'demo1', 'staging', 'dev', 'local',
        'hamyar', 'platform', 'system', 'root', 'null', 'undefined',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{name: string, subdomain: string, owner_name: string, owner_mobile: string, owner_email?: string|null, password: string}  $input
     */
    public function provision(array $input): Tenant
    {
        return DB::transaction(function () use ($input): Tenant {
            $tenant = Tenant::query()->create([
                'name' => $input['name'],
                'slug' => $input['subdomain'],
                // `tenants.status` is about whether WE have suspended a shop, not about
                // what it pays — a suspended or archived tenant cannot log in at all
                // (`ResolveTenant`). A new shop is simply active; its plan decides what it
                // may do, and the free rung means that answer is never "nothing".
                'status' => Tenant::STATUS_ACTIVE,
                'trial_ends_at' => null,
                'settings' => [
                    'currency_display' => config('app.currency_display', 'toman'),
                    'digits' => 'fa',
                ],
            ]);

            Domain::query()->create([
                'tenant_id' => $tenant->getKey(),
                'hostname' => Domain::hostnameFor($input['subdomain']),
                'is_primary' => true,
            ]);

            $this->startOnFreePlan($tenant);

            /*
            | Everything below writes tenant-scoped rows, so RLS needs the context —
            | without it the inserts are rejected by the policy's WITH CHECK clause,
            | which is exactly the protection working as intended.
            |
            | savepoint-allow: `runFor()` restores the tenant id in a `finally`, so
            | `bin/check-savepoint-recovery` flags it inside a transaction — correctly, in
            | general. It cannot be hoisted here: `runFor($tenant, …)` needs the tenant that
            | this very transaction is in the middle of creating, so wrapping the transaction
            | would mean entering a context for a row that does not exist yet.
            |
            | Safe because the hazard that rule prevents is a `finally`'s 25P02 **replacing**
            | an exception somebody was catching. Nothing catches here: a duplicate mobile
            | during signup fails the request either way, and the only cost is that the log
            | shows 25P02 where 23505 would be clearer. Add a `catch` inside this transaction
            | and that stops being true — read the rule before you do.
            */
            return $this->context->runFor($tenant, function () use ($tenant, $input): Tenant {
                $this->seedRoles($tenant);

                $owner = User::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'name' => $input['owner_name'],
                    'mobile' => $input['owner_mobile'],
                    'email' => $input['owner_email'] ?? null,
                    'password' => $input['password'],
                    'is_active' => true,
                ]);

                $owner->assignRole('Owner');

                // Other modules set up their own defaults from here — Inventory creates
                // the first branch and warehouse. Platform must not reach into them
                // (golden rule 6), and the listener runs inside this transaction and
                // this tenant context, so a failure there rolls the whole signup back
                // rather than leaving a shop with no warehouse.
                event(new TenantProvisioned($tenant));

                return $tenant;
            });
        });
    }

    /**
     * Put a new shop on the free plan, permanently.
     *
     * ## Why there is no trial any more
     *
     * There used to be: fourteen days of the Professional plan, so an evaluating shop
     * would see تعمیرات and اقساط — the modules that differentiated the product. That was
     * the right shape when a plan bought modules. Since DECISION GATE 6 every module is
     * open and a plan buys **quantity**, so the thing to evaluate is the product itself,
     * for as long as the shopkeeper wants, on credits sized for a small shop.
     *
     * The free plan is therefore not a trial with the timer removed — it is a rung, and a
     * shop can stay on it for ever. That also gives a lapsed paid subscription somewhere
     * coherent to land (`LimitResolver`'s fallback) instead of a lock-out.
     *
     * **No period.** A zero-price subscription has nothing to renew, so
     * `current_period_end` stays null — which `Subscription::isUsable()` already reads as
     * "usable" for an active row, and `BillingService::hasLivePeriod()` already reads as
     * "nothing to prorate against", so the first paid plan is charged in full rather than
     * discounted against a period that cost nothing.
     *
     * Falls back to the cheapest plan when no zero-price one exists, and returns null when
     * the catalogue has not been synced at all — provisioning a shop must never fail
     * because a seed is missing.
     *
     * Runs inside `runAsPlatform()`: onboarding happens on the central domain, where there
     * is no tenant context, and `subscriptions` is RLS-protected.
     */
    public function startOnFreePlan(Tenant $tenant): ?Subscription
    {
        $plan = Plan::query()->where('price', 0)->orderBy('position')->first()
            ?? Plan::query()->orderBy('price')->first();

        if (! $plan instanceof Plan) {
            return null;
        }

        $now = CarbonImmutable::now();

        return $this->context->runAsPlatform(fn (): Subscription => Subscription::query()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $now,
            // Deliberately null. See the docblock: free has no period to end.
            'current_period_end' => null,
        ]));
    }

    /**
     * Create this tenant's copy of the seven default roles.
     *
     * Permissions are central and created once; roles are per-tenant so a shop can
     * change what its own Salesperson may do without affecting anybody else.
     */
    public function seedRoles(Tenant $tenant): void
    {
        // Establishes the context itself rather than assuming an ambient one. It takes
        // the tenant explicitly, so a caller reasonably expects it to just work — and
        // without this the role inserts are rejected by the RLS policy's WITH CHECK,
        // which is a confusing way to learn about a missing context.
        // runFor() restores whatever was set before, so nesting inside provision()
        // is safe.
        $this->context->runFor($tenant, function () use ($tenant): void {
            self::syncPermissionCatalogue();

            $this->createSystemRoles($tenant);
        });
    }

    private function createSystemRoles(Tenant $tenant): void
    {
        foreach (PermissionCatalogue::roles() as $name => $definition) {
            /** @var Role $role */
            $role = Role::query()->firstOrCreate(
                [
                    'name' => $name,
                    'guard_name' => 'web',
                    'tenant_id' => $tenant->getKey(),
                ],
                [
                    'name_fa' => $definition['name_fa'],
                    'is_system' => true,
                ]
            );

            $role->syncPermissions(PermissionCatalogue::permissionsFor($name));
        }
    }

    /**
     * Make sure every `module.action` in the catalogue exists as a permission row.
     *
     * Central and idempotent, so shipping a new capability is a code change plus a
     * `tenancy:sync-permissions` run rather than a migration per tenant.
     */
    public static function syncPermissionCatalogue(): void
    {
        $existing = Permission::query()->pluck('name')->all();

        foreach (PermissionCatalogue::permissions() as $module => $actions) {
            foreach ($actions as $action => $description) {
                $name = "{$module}.{$action}";

                if (in_array($name, $existing, true)) {
                    continue;
                }

                Permission::query()->create([
                    'name' => $name,
                    'guard_name' => 'web',
                    'module' => $module,
                    'description_fa' => $description,
                ]);
            }
        }
    }
}
