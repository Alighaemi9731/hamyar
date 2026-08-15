<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\CRM\Models\Account;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A year of trading at a volume no demo shop will ever reach — the fixture the
 * performance budget is measured against.
 *
 * `docs/specs/reporting.md` promises the top reports return in **under 300ms on a
 * 100k-row seed**, and `docs/ROADMAP.md` 9.3 asserts it in CI. Neither sentence can be
 * written without this file: a budget measured against the demo shop's eleven invoices
 * measures nothing, and the missing index it is supposed to catch would not cost a
 * millisecond at that size.
 *
 * ## This is NOT a correctness fixture, and no golden number may be pinned to it
 *
 * {@see CrazyMonthSeeder} drives every figure through the real services, because its job
 * is to prove the ledger balances. This one does the opposite and inserts rows directly,
 * because its job is to prove a *query plan* holds — and the two goals want opposite
 * things:
 *
 * - 40,000 invoices through `FinaliseInvoice` would take the better part of an hour, and
 *   would prove nothing the Phase 5 suite has not already proved once.
 * - Every amount below is arithmetic invented by this file. Asserting a report figure
 *   against it would be pinning the report to the seeder's own invention — the
 *   *green without witness* trap in `docs/testing.md`, one step worse, because the
 *   fixture would contain plenty of rows and the number would still mean nothing.
 *
 * The rule is therefore blunt: **assert timings and row counts here, never money.**
 * Money is `CrazyMonthSeeder`'s and `SalesReportScreenTest`'s job.
 *
 * ## Deterministic, never random
 *
 * Every value is a function of the row's ordinal through coprime multipliers — no
 * `random()`, no Faker. A performance fixture that reshuffles per run gives a budget that
 * moves per run, and the first flake gets blamed on the CI machine rather than on the
 * plan change that actually caused it. Same seed, same rows, same plan, every time.
 *
 * ## Two shops of equal size, on purpose
 *
 * A single-tenant table is the one place a sequential scan looks exactly like an index
 * scan, because scanning everything and scanning one tenant's rows are the same work. So
 * the neighbour is seeded to the same volume, and the tenant predicate has to earn its
 * keep. That is also why the counts below describe **one** shop: the table holds twice
 * what the report reads.
 *
 * ## ANALYZE after every step, not once at the end
 *
 * Postgres plans from statistics, and a freshly bulk-loaded table has none: the planner
 * believes it holds a handful of rows. Autovacuum cannot help — the rows are still
 * uncommitted, and under `RefreshDatabase` they never commit at all.
 *
 * That matters twice over, and the second one is the expensive one:
 *
 * 1. **The reports would be measured against a planner that is guessing.** A sequential
 *    scan over a table the planner thinks is tiny is chosen instantly, so the budget
 *    would pass while proving nothing about any index.
 * 2. **The seeder's own later statements plan badly.** Written with a single ANALYZE at
 *    the end, the sale-movement insert — which joins 100,000 unanalysed items to 40,000
 *    unanalysed invoices — was still running after **seven minutes**, against 1.4
 *    seconds once the two tables had statistics. It had looked instant during
 *    development on a dev database that autovacuum had already been through.
 *
 * So each step analyses what it wrote, before the next step reads it.
 */
final class BulkVolumeSeeder extends Seeder
{
    /**
     * The window the seeded year occupies.
     *
     * It ends where {@see CrazyMonthSeeder} ends so both fixtures speak about the same
     * "now", and it is a literal for the same reason that one is: a fixture whose dates
     * are relative to today produces a report range that means something different in
     * Mordad than in Shahrivar.
     */
    public const YEAR_END = '2026-08-22';

    public const DAYS = 365;

    /** Invoices per shop. At ~2.5 lines each this is the 100,000 the budget names. */
    public const INVOICES = 40_000;

    public const PARTIES = 3_000;

    public const PRODUCTS = 400;

    /** Two variants per product — derived, so the two cannot drift apart. */
    public const VARIANTS = self::PRODUCTS * 2;

    public const BRANDS = 20;

    public const SALESPEOPLE = 6;

    /**
     * `db:seed --class=Database\Seeders\BulkVolumeSeeder` — fills both demo shops.
     *
     * Never called by `DatabaseSeeder`. `make fresh` has to stay fast enough to run
     * between two attempts at a bug, and nobody wants 660,000 rows in the way of a
     * manual walk.
     *
     * `acme` is filled too, and that is a deliberate departure: `DatabaseSeeder` leaves
     * it empty so a leak into it cannot be mistaken for a legitimate row. Here the
     * opposite is wanted — a neighbour with nothing in it makes every index look good —
     * so this command is the one place the empty shop stops being empty, and running it
     * is opting out of that property for the session.
     */
    public function run(): void
    {
        foreach (['demo', 'acme'] as $slug) {
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if (! $tenant instanceof Tenant) {
                continue;
            }

            $counts = $this->fill($tenant);

            $this->command?->info(sprintf(
                '  %-6s %s invoices · %s items · %s movements · %s ledger rows',
                $slug,
                number_format($counts['invoices']),
                number_format($counts['items']),
                number_format($counts['movements']),
                number_format($counts['ledger_entries']),
            ));
        }

        app(TenantContext::class)->forget();
    }

    /**
     * Fill one shop with `$invoices` invoices' worth of a year, and report what landed.
     *
     * The return value is not decoration. A latency test that does not first assert the
     * volume it thinks it measured is measuring an empty table at full speed — so the
     * counts come back as data and the caller asserts them.
     *
     * @return array{invoices: int, items: int, movements: int, ledger_entries: int, parties: int, variants: int}
     */
    public function fill(Tenant $tenant, int $invoices = self::INVOICES): array
    {
        /** @var array{invoices: int, items: int, movements: int, ledger_entries: int, parties: int, variants: int} $counts */
        $counts = app(TenantContext::class)->runFor($tenant, function () use ($tenant, $invoices): array {
            $tenantId = $this->keyOf($tenant);

            $structure = $this->seedStructure($tenantId);

            $this->seedCatalogue($tenantId);
            $this->analyse('brands', 'products', 'product_variants');

            $this->seedParties($tenantId);
            $this->analyse('parties');

            $this->seedInvoices($tenantId, $invoices, $structure);
            $this->analyse('sales_invoices');

            $this->seedItems($tenantId);
            $this->analyse('sales_invoice_items');

            $this->settleInvoiceTotals($tenantId);

            $this->seedStockMovements($tenantId, $structure);
            $this->analyse('stock_movements');

            $this->seedLedger($tenantId, $structure);
            $this->analyse('ledger_entries');

            return [
                'invoices' => $this->countOf('sales_invoices', $tenantId),
                'items' => $this->countOf('sales_invoice_items', $tenantId),
                'movements' => $this->countOf('stock_movements', $tenantId),
                'ledger_entries' => $this->countOf('ledger_entries', $tenantId),
                'parties' => $this->countOf('parties', $tenantId),
                'variants' => $this->countOf('product_variants', $tenantId),
            ];
        });

        return $counts;
    }

    /* ------------------------------------------------------- the dimensions -- */

    /**
     * Branches, warehouses, salespeople and the two accounts the ledger posts into.
     *
     * Small enough to go through the models, and better for it: `Branch` and `Warehouse`
     * carry partial unique indexes on their defaults, and reproducing those rules in raw
     * SQL is how a seeder ends up with two default warehouses and a stock query that
     * silently reads one of them.
     *
     * @return array{branches: list<int>, warehouses: list<int>, salespeople: list<int>, sales_account: int, inventory_account: int}
     */
    private function seedStructure(int $tenantId): array
    {
        $branches = $this->ints(Branch::query()->orderBy('id')->pluck('id'));

        if (count($branches) < 2) {
            $branches[] = $this->keyOf(Branch::query()->create([
                'name' => 'شعبه دوم',
                'code' => 'B2',
                'is_default' => false,
                'is_active' => true,
            ]));
        }

        $warehouses = [];

        foreach ($branches as $branchId) {
            $warehouse = Warehouse::query()->where('branch_id', $branchId)->where('is_sellable', true)->first();

            $warehouses[] = $this->keyOf($warehouse ?? Warehouse::query()->create([
                'branch_id' => $branchId,
                'name' => 'انبار فروش',
                'is_sellable' => true,
                'is_default' => Warehouse::query()->where('branch_id', $branchId)->where('is_default', true)->doesntExist(),
                'is_active' => true,
            ]));
        }

        $salespeople = $this->ints(User::query()->orderBy('id')->pluck('id'));

        /*
        | Built here rather than through `User::factory()`, which hard-codes `bcrypt()`.
        | That is right for the suite, where the hash driver is bcrypt, and wrong the
        | moment this seeder is run against a dev database on the app's real Argon2id —
        | the `hashed` cast rejects the foreign algorithm with «Could not verify the
        | hashed value's configuration», which reads like a corrupt row rather than a
        | mismatched driver. `Hash::make()` uses whatever is configured, everywhere.
        */
        for ($n = count($salespeople); $n < self::SALESPEOPLE; $n++) {
            $salespeople[] = $this->keyOf(User::query()->create([
                'name' => 'فروشنده '.($n + 1),
                'email' => "seller{$n}.volume@example.test",
                'mobile' => '0912'.str_pad((string) (900000 + $tenantId * 10 + $n), 7, '0', STR_PAD_LEFT),
                'password' => Hash::make('password'),
                'is_active' => true,
            ]));
        }

        $sales = Account::query()->firstOrCreate(
            ['type' => Account::TYPE_SALES],
            ['name' => 'درآمد فروش', 'is_active' => true],
        );

        $inventory = Account::query()->firstOrCreate(
            ['type' => Account::TYPE_INVENTORY],
            ['name' => 'ارزش موجودی انبار', 'is_active' => true],
        );

        return [
            'branches' => $branches,
            'warehouses' => $warehouses,
            'salespeople' => array_slice($salespeople, 0, self::SALESPEOPLE),
            'sales_account' => $this->keyOf($sales),
            'inventory_account' => $this->keyOf($inventory),
        ];
    }

    /**
     * Brands, products and variants — the join targets of «فروش بر اساس محصول/برند».
     *
     * Cardinality matters more than realism here: 400 products over 20 brands is what
     * makes a grouped report aggregate 100,000 rows into a few hundred, which is the
     * shape whose plan the budget is about.
     */
    private function seedCatalogue(int $tenantId): void
    {
        DB::insert(<<<'SQL'
            insert into brands (tenant_id, name, name_fa, position, created_at, updated_at)
            select ?, 'Brand ' || g, 'برند ' || g, g, now(), now()
            from generate_series(1, ?) g
            on conflict do nothing
        SQL, [$tenantId, self::BRANDS]);

        $brands = $this->idArray('brands', $tenantId);

        DB::insert(<<<'SQL'
            insert into products (tenant_id, brand_id, name, type, is_active, low_stock_threshold, created_at, updated_at)
            select
                ?,
                (?::bigint[])[1 + (g % array_length(?::bigint[], 1))],
                'کالای فروشگاهی ' || g,
                'standard',
                true,
                case when g % 5 = 0 then 3 else null end,
                now(),
                now()
            from generate_series(1, ?) g
        SQL, [$tenantId, $brands, $brands, self::PRODUCTS]);

        /*
        | Two variants per product, and the SKU/barcode are prefixed with the tenant id.
        | Those columns carry partial unique indexes scoped to the tenant, so a bare
        | sequence would collide the moment the neighbour is seeded into the same table.
        */
        DB::insert(<<<'SQL'
            insert into product_variants (tenant_id, product_id, options, sku, barcode, is_active, created_at, updated_at)
            select
                ?,
                p.id,
                jsonb_build_object('حافظه', case when line = 1 then '128GB' else '256GB' end),
                'SKU-' || ? || '-' || p.id || '-' || line,
                '629' || lpad((? * 1000000 + p.id * 10 + line)::text, 10, '0'),
                true,
                now(),
                now()
            from products p, generate_series(1, 2) line
            where p.tenant_id = ?
        SQL, [$tenantId, $tenantId, $tenantId, $tenantId]);
    }

    /**
     * Customers, named from a small pool so that ordering and grouping by name behave
     * the way they do on real data rather than on a column of distinct integers.
     */
    private function seedParties(int $tenantId): void
    {
        DB::insert(<<<'SQL'
            insert into parties (tenant_id, kind, name, opening_balance, is_active, created_at, updated_at)
            select
                ?,
                'customer',
                (array['رضا','سارا','مهدی','نگار','امیر','الهام','حسین','مریم'])[1 + (g % 8)]
                    || ' ' ||
                (array['محمدی','احمدی','رضایی','کریمی','موسوی','نجفی','قاسمی','زند'])[1 + ((g / 8) % 8)]
                    || ' ' || g,
                0,
                true,
                now(),
                now()
            from generate_series(1, ?) g
        SQL, [$tenantId, self::PARTIES]);
    }

    /* ------------------------------------------------------------ the facts -- */

    /**
     * A year of invoices, spread across every day of it.
     *
     * The spread is `(g * 37) % 365`: 37 is coprime with 365, so the ordinals walk every
     * day exactly once per cycle and no day is empty. A `g % 365` would work too but
     * would put consecutive ids on consecutive days, which makes an index range scan
     * look far better than it is — the rows a real range touches are scattered.
     *
     * One in twenty is a quote and one in fifty is voided, so the type and status
     * predicates in {@see \App\Modules\Reporting\Services\SalesReports} filter something.
     * A fixture where every row qualifies cannot tell a plan that uses the status index
     * from one that ignores it.
     *
     * Foreign keys are picked by **subscripting an array of ids**, never by a correlated
     * `offset … limit 1`. The offset form reads as the obvious way to say "some customer"
     * and is quadratic: Postgres walks `offset` rows to discard them, once per invoice,
     * which turned a two-second seed into a four-minute one.
     *
     * @param  array{branches: list<int>, salespeople: list<int>, ...}  $structure
     */
    private function seedInvoices(int $tenantId, int $invoices, array $structure): void
    {
        $branches = '{'.implode(',', $structure['branches']).'}';
        $salespeople = '{'.implode(',', $structure['salespeople']).'}';
        $parties = $this->idArray('parties', $tenantId);
        $start = CarbonImmutable::parse(self::YEAR_END)->subDays(self::DAYS - 1)->startOfDay();

        DB::insert(<<<'SQL'
            insert into sales_invoices (
                tenant_id, branch_id, party_id, salesperson_id, number, type, status, issued_at,
                subtotal, discount_amount, vat_amount, shipping_amount, rounding_adjustment,
                total, paid_total, commission_amount, commission_rate, created_at, updated_at
            )
            select
                ?,
                (?::bigint[])[1 + (g % array_length(?::bigint[], 1))],
                (?::bigint[])[1 + ((g * 101) % array_length(?::bigint[], 1))],
                (?::bigint[])[1 + ((g * 13) % array_length(?::bigint[], 1))],
                'INV-' || lpad(g::text, 7, '0'),
                case when g % 20 = 0 then 'quote' else 'invoice' end,
                case
                    when g % 20 = 0 then 'draft'
                    when g % 50 = 0 then 'void'
                    else 'final'
                end,
                -- Business hours, so a Tehran-day boundary has traffic on both sides of it.
                ?::timestamp
                    + make_interval(days => (g * 37) % ?)
                    + make_interval(hours => 9 + (g % 11), mins => (g * 7) % 60),
                0, 0, 0, 0, 0, 0, 0, 0, 0,
                now(),
                now()
            from generate_series(1, ?) g
        SQL, [
            $tenantId,
            $branches, $branches,
            $parties, $parties,
            $salespeople, $salespeople,
            $start->toDateTimeString(), self::DAYS,
            $invoices,
        ]);
    }

    /**
     * Two or three lines per invoice, on a variant chosen far away from the invoice's own
     * ordinal so that one product's sales are scattered across the year rather than
     * clustered into a fortnight.
     *
     * Amounts are deliberately awkward — 87,340,000 and not 90,000,000 — because a
     * fixture built out of round numbers is how a rounding guard goes untested. That
     * lesson is `docs/testing.md`'s; it costs nothing to honour it here.
     */
    private function seedItems(int $tenantId): void
    {
        $variants = $this->idArray('product_variants', $tenantId);

        DB::insert(<<<'SQL'
            insert into sales_invoice_items (
                tenant_id, sales_invoice_id, product_variant_id, description, quantity,
                unit_price, discount_amount, vat_rate, vat_amount, line_total, cost_snapshot,
                is_service, created_at, updated_at
            )
            select
                ?,
                i.id,
                (?::bigint[])[1 + ((i.id * 53 + line) % array_length(?::bigint[], 1))],
                'قلم فروش ' || line,
                q.quantity,
                price.unit_price,
                0,
                0,
                0,
                price.unit_price * q.quantity,
                -- Cost is ~78% of price, varied per line so margin is not a constant.
                (price.unit_price * 78 / 100) - ((i.id % 7) * 10000),
                false,
                now(),
                now()
            from sales_invoices i
            join lateral generate_series(1, 2 + (i.id % 2)) line on true
            join lateral (select 1 + ((i.id * 31 + line * 7) % 3) as quantity) q on true
            join lateral (select 1230000 + ((i.id * 17 + line * 911) % 96) * 910000 as unit_price) price on true
            where i.tenant_id = ?
              and i.deleted_at is null
        SQL, [$tenantId, $variants, $variants, $tenantId]);
    }

    /**
     * Totals derived from the lines, in one set-based pass.
     *
     * Deliberately not written alongside the invoice: an invoice total that is anything
     * other than the sum of its lines is a fixture that would make a reconciliation
     * report disagree with itself for a reason that has nothing to do with the code. This
     * is the same direction of causation {@see \App\Modules\Sales\Services\InvoiceTotals}
     * enforces — lines are the fact, the total is the consequence.
     */
    private function settleInvoiceTotals(int $tenantId): void
    {
        DB::update(<<<'SQL'
            update sales_invoices i
            set subtotal = lines.subtotal,
                total = lines.subtotal,
                paid_total = case when i.status = 'final' then lines.subtotal else 0 end
            from (
                select sales_invoice_id, sum(line_total) as subtotal
                from sales_invoice_items
                where tenant_id = ?
                group by sales_invoice_id
            ) lines
            where lines.sales_invoice_id = i.id
              and i.tenant_id = ?
        SQL, [$tenantId, $tenantId]);
    }

    /**
     * The quantity ledger: a purchase per variant per month, and a sale per invoice line.
     *
     * On-hand is a SUM over this table (golden rule 3), so its size *is* the stock
     * report's cost. Purchases come first and are large enough that the running total
     * never goes negative — not because a negative would fail here (nothing checks a raw
     * insert) but because a stock report reading negatives everywhere would look broken
     * to anyone opening the seeded shop to eyeball a screen.
     *
     * @param  array{warehouses: list<int>, ...}  $structure
     */
    private function seedStockMovements(int $tenantId, array $structure): void
    {
        $warehouses = '{'.implode(',', $structure['warehouses']).'}';
        $start = CarbonImmutable::parse(self::YEAR_END)->subDays(self::DAYS - 1)->startOfDay();

        DB::insert(<<<'SQL'
            insert into stock_movements (tenant_id, product_variant_id, warehouse_id, quantity, type, unit_cost, occurred_at, created_at)
            select
                ?,
                v.id,
                (?::bigint[])[1 + (v.id % array_length(?::bigint[], 1))],
                400,
                'purchase',
                9000000,
                ?::timestamp + make_interval(days => month * 30),
                now()
            from product_variants v, generate_series(0, 11) month
            where v.tenant_id = ?
        SQL, [$tenantId, $warehouses, $warehouses, $start->toDateTimeString(), $tenantId]);

        DB::insert(<<<'SQL'
            insert into stock_movements (tenant_id, product_variant_id, warehouse_id, quantity, type, unit_cost, reference_type, reference_id, occurred_at, created_at)
            select
                ?,
                it.product_variant_id,
                (?::bigint[])[1 + (it.product_variant_id % array_length(?::bigint[], 1))],
                -it.quantity,
                'sale',
                it.cost_snapshot,
                'sales_invoice',
                i.id,
                i.issued_at,
                now()
            from sales_invoice_items it
            join sales_invoices i on i.id = it.sales_invoice_id
            where it.tenant_id = ?
              and i.status = 'final'
              and it.product_variant_id is not null
        SQL, [$tenantId, $warehouses, $warehouses, $tenantId]);
    }

    /**
     * Two balancing lines per finalised invoice — the party owes, sales earns.
     *
     * Enough to give party-balance aging and the P&L a table of the right order of
     * magnitude to read. It balances, which costs nothing to arrange here, but see the
     * class docblock: that is a courtesy to whoever opens the seeded shop, not a claim
     * any test may lean on.
     *
     * @param  array{sales_account: int, ...}  $structure
     */
    private function seedLedger(int $tenantId, array $structure): void
    {
        DB::insert(<<<'SQL'
            insert into ledger_entries (tenant_id, party_id, account_id, branch_id, debit, credit, reference_type, reference_id, batch_id, description, occurred_at, created_at)
            select
                ?,
                case when side = 1 then i.party_id else null end,
                -- The cast is load-bearing: a bare placeholder inside a CASE arrives as
                -- text and Postgres refuses to guess, which reads as a schema error.
                case when side = 1 then null else ?::bigint end,
                i.branch_id,
                case when side = 1 then i.total else 0 end,
                case when side = 1 then 0 else i.total end,
                'sales_invoice',
                i.id,
                -- Deterministic uuid: both sides of one invoice share a batch, and the
                -- batch is the same on every run. gen_random_uuid() would reshuffle the
                -- table's physical order between runs and move the timings with it.
                md5('batch:' || i.id::text)::uuid,
                'فروش ' || i.number,
                i.issued_at,
                now()
            from sales_invoices i, generate_series(1, 2) side
            where i.tenant_id = ?
              and i.status = 'final'
              and i.total > 0
              and i.party_id is not null
        SQL, [$tenantId, $structure['sales_account'], $tenantId]);
    }

    /* ----------------------------------------------------------- statistics -- */

    /**
     * Hand the planner the statistics it would have in production.
     *
     * Called after each step rather than once at the end — see the class docblock for
     * the seven-minute insert that made the difference concrete.
     */
    private function analyse(string ...$tables): void
    {
        foreach ($tables as $table) {
            DB::statement("analyze {$table}");
        }
    }

    private function countOf(string $table, int $tenantId): int
    {
        return (int) DB::table($table)->where('tenant_id', $tenantId)->count();
    }

    /**
     * A shop's ids for one table, as a Postgres array literal to subscript in SQL.
     *
     * Passed as a bound parameter and cast with `::bigint[]`, so it survives ids that
     * are not contiguous — and they are not, once two shops have been seeded into the
     * same sequence. Deriving a foreign key as `min(id) + n` looks equivalent and breaks
     * silently on the neighbour, pointing every one of its invoices at the first shop's
     * customers, which RLS then hides — leaving a report that returns nothing and no
     * error anywhere.
     */
    private function idArray(string $table, int $tenantId): string
    {
        $ids = $this->ints(DB::table($table)->where('tenant_id', $tenantId)->orderBy('id')->pluck('id'));

        return '{'.implode(',', $ids).'}';
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return list<int>
     */
    private function ints(Collection $values): array
    {
        return array_values($values->map(fn (mixed $value): int => is_numeric($value) ? (int) $value : 0)->all());
    }

    private function keyOf(Model $model): int
    {
        $key = $model->getKey();

        return is_numeric($key) ? (int) $key : 0;
    }
}
