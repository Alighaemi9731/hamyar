<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\TenantProvisioner;
use Illuminate\Database\Seeder;

/**
 * Root seeder — `make fresh`.
 *
 * Seeds **two** tenants on purpose, not one. The cross-tenant isolation suite needs
 * something to cross, and a single-tenant dev database is exactly the environment in
 * which a leak is invisible.
 */
class DatabaseSeeder extends Seeder
{
    /*
     * `WithoutModelEvents` is deliberately NOT used here.
     *
     * It mutes model events for the whole seeder run, and `BelongsToTenant` fills
     * `tenant_id` from a `creating` hook — so every tenant-scoped insert would arrive
     * with a null tenant and be rejected by its own RLS policy. Nothing caught it
     * while the seeder only created central rows; the first seeded product did.
     */

    public function run(TenantProvisioner $provisioner, PlanCatalogueSeeder $catalogue): void
    {
        // Plans and modules first: provisioning puts each new shop on a trial, which
        // needs a plan to point at.
        $catalogue->sync();

        PlatformUser::query()->create([
            'name' => 'مدیر پلتفرم',
            'email' => 'admin@mobishop.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $demo = $provisioner->provision([
            'name' => 'موبایل دمو',
            'subdomain' => 'demo',
            'owner_name' => 'رضا محمدی',
            'owner_mobile' => '09121234567',
            'owner_email' => 'admin@demo.test',
            'password' => 'password',
        ]);

        $acme = $provisioner->provision([
            'name' => 'موبایل آکمه',
            'subdomain' => 'acme',
            'owner_name' => 'سارا احمدی',
            'owner_mobile' => '09129876543',
            'owner_email' => 'admin@acme.test',
            'password' => 'password',
        ]);

        // Only the demo shop gets stock. `acme` stays deliberately empty: it is what
        // the isolation suite crosses into, and a shop with data in it makes a leak
        // look like a legitimate row.
        $this->call(DemoShopSeeder::class);

        $domain = config()->string('app.domain');

        $this->command?->newLine();
        $this->command?->info("  Platform admin  http://{$domain}  admin@mobishop.test / password");
        $this->command?->info("  Tenant {$demo->slug}     http://{$demo->slug}.{$domain}/login  09121234567 / password");
        $this->command?->info("  Tenant {$acme->slug}     http://{$acme->slug}.{$domain}/login  09129876543 / password");
    }
}
