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
use Illuminate\Support\Str;
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
     * `admin.mobishop.ir` or `support.mobishop.ir` could impersonate us convincingly
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
        'mobishop', 'platform', 'system', 'root', 'null', 'undefined',
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
                'status' => Tenant::STATUS_TRIALING,
                'trial_ends_at' => now()->addDays(14),
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

            $this->startTrial($tenant);

            // Everything below writes tenant-scoped rows, so RLS needs the context —
            // without it the inserts are rejected by the policy's WITH CHECK clause,
            // which is exactly the protection working as intended.
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
     * Put a new shop on a 14-day trial of the mid-tier plan.
     *
     * The trial deliberately grants the PRO plan rather than Basic: a shop evaluating
     * us needs to see repairs and installments, which are the features that actually
     * differentiate the product. Selling Basic to someone who never saw Repairs is
     * how you lose the upsell and the customer.
     *
     * Falls back silently when the catalogue has not been synced yet — provisioning a
     * shop must never fail because a seed is missing.
     *
     * Runs inside `runAsPlatform()`: onboarding happens on the central domain, where
     * there is no tenant context, and `subscriptions` is RLS-protected. The platform is
     * the party writing this row, so it says so explicitly.
     */
    public function startTrial(Tenant $tenant): ?Subscription
    {
        $plan = Plan::query()->where('code', 'pro')->first()
            ?? Plan::query()->orderBy('price')->first();

        if (! $plan instanceof Plan) {
            return null;
        }

        $now = CarbonImmutable::now();
        $trialEnds = $now->addDays($plan->trial_days);

        return $this->context->runAsPlatform(fn (): Subscription => Subscription::query()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => Subscription::STATUS_TRIALING,
            'trial_ends_at' => $trialEnds,
            'current_period_start' => $now,
            'current_period_end' => $trialEnds,
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

    /**
     * Is this subdomain available and legal?
     *
     * @return array{ok: bool, reason?: string}
     */
    public function checkSubdomain(string $subdomain): array
    {
        $subdomain = Str::lower(trim($subdomain));

        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$/', $subdomain) !== 1) {
            return ['ok' => false, 'reason' => 'نشانی فروشگاه باید ۳ تا ۳۰ نویسه انگلیسی، عدد یا خط تیره باشد و با حرف یا عدد شروع و تمام شود.'];
        }

        if (str_contains($subdomain, '--')) {
            // Two hyphens in a row is the IDN "punycode" prefix form; disallowing it
            // avoids a class of homograph lookalikes.
            return ['ok' => false, 'reason' => 'دو خط تیره پشت‌سرهم مجاز نیست.'];
        }

        if (in_array($subdomain, self::RESERVED_SUBDOMAINS, true)) {
            return ['ok' => false, 'reason' => 'این نشانی رزرو شده است. لطفاً نشانی دیگری انتخاب کنید.'];
        }

        $taken = Domain::query()->where('hostname', Domain::hostnameFor($subdomain))->exists();

        if ($taken) {
            return ['ok' => false, 'reason' => 'این نشانی قبلاً گرفته شده است.'];
        }

        return ['ok' => true];
    }
}
