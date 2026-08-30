<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Platform\Services\PlanCatalogueSeeder;
use Illuminate\Database\Seeder;

/**
 * The plan catalogue, and nothing else, for the test suite.
 *
 * ## Why the suite needs it at all
 *
 * In production a catalogue always exists — `DatabaseSeeder` syncs it and
 * `PlanCatalogueSeeder` is idempotent and runs on every deploy. A database with no plans is
 * a state this application never sees outside a test, and Phase 12 made that load-bearing:
 * `LimitResolver` throws when the fallback plan is missing, deliberately, because the
 * lenient reading hands every lapsed shop unlimited everything.
 *
 * ## Why it is a Seeder class rather than a `beforeEach`
 *
 * It was a `beforeEach` in `tests/Pest.php` until `0.19.0` — ~1486 runs at 76ms each, or
 * **113 seconds of a 780-second suite**, re-proving the same eighteen modules and three
 * plans over and over.
 *
 * Moving it is harder than it looks, and two obvious seams do not work:
 *
 * - **`afterRefreshingDatabase()`** is called *after* `refreshTestDatabase()` has already
 *   opened the per-test transaction, so a seed there still runs per test AND is rolled back
 *   with it.
 * - **Overriding `migrateDatabases()` on `Tests\TestCase`** never executes. Pest applies
 *   `RefreshDatabase` to the generated test class, and a trait method takes precedence over
 *   an inherited parent method — so the trait's version wins and the override is dead code
 *   that looks alive.
 *
 * What does work is the seam the framework offers for exactly this: `RefreshDatabase` reads
 * `$this->seeder` (a *property*, which does resolve through inheritance) and passes it to
 * `migrate:fresh --seeder=…`. That runs once per process, as part of the migration, before
 * any transaction opens — so what it writes survives every rollback.
 *
 * ## Deliberately only the catalogue
 *
 * Not `DatabaseSeeder`, which also provisions a demo tenant: a tenant that exists before
 * every test would hide cross-tenant bugs and slow the suite back down. And no
 * *subscription* — a tenant without one resolves to the free plan and is metered at its
 * credits, exactly like a real shop whose payment lapsed. Seeding a subscription by default
 * is the shortcut that lets a create path forget `consume()` and stay green for ever.
 */
final class TestCatalogueSeeder extends Seeder
{
    public function run(PlanCatalogueSeeder $catalogue): void
    {
        $catalogue->sync();
    }
}
