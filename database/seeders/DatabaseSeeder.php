<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Root seeder — `make fresh`.
 *
 * Phase 0 only has the framework's `users` table, so this seeds the single account
 * that lets you log into the shell and click around.
 *
 * Phase 1 replaces this with the real onboarding path: two tenants (`demo` and
 * `acme`), their domains, the seven seeded roles, and a `DemoDataSeeder` that builds
 * a believable Persian shop — products with real model names, customers, a repair
 * board mid-flow, cheques at various stages, and a month of sales that reconciles.
 * Two tenants, not one, because the isolation suite needs something to cross.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->create([
            'name' => 'مدیر دمو',
            'email' => 'admin@demo.test',
            // Password is `password`; documented in the Makefile output and README.
        ]);

        $this->command?->newLine();
        $this->command?->info('  Demo login: admin@demo.test / password');
    }
}
