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
     * Handsets in the unit register.
     *
     * Not a round number of invoices' worth, and not meant to be: what this unlocks is the
     * *other* half of a valuation. Standard goods are a SUM over `stock_movements` and
     * handsets are rows here with no movement written for them, so before these existed a
     * timed valuation measured one half at 100,000 rows and the other at zero — and
     * `profit.perUnit` could not be measured at all, which `ReportLatencyTest` said in
     * writing and deferred.
     */
    public const UNITS = 5_000;

    /** Cheques per shop — a year of paper, both directions. */
    public const CHEQUES = 2_000;

    /** Instalment contracts, each with six rows and about half of them part-collected. */
    public const PLANS = 1_000;

    public const PLAN_ROWS = 6;

    /** A year of automations firing. */
    public const MESSAGES = 20_000;

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
     * @return array{invoices: int, items: int, movements: int, ledger_entries: int, parties: int, variants: int, units: int, cheques: int, installment_rows: int, messages: int}
     */
    public function fill(Tenant $tenant, int $invoices = self::INVOICES): array
    {
        /** @var array{invoices: int, items: int, movements: int, ledger_entries: int, parties: int, variants: int, units: int, cheques: int, installment_rows: int, messages: int} $counts */
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

            $this->seedUnits($tenantId, $structure);
            $this->analyse('product_units');

            $this->seedCheques($tenantId, $structure);
            $this->analyse('cheques');

            $this->seedInstallments($tenantId, $structure);
            $this->analyse('installment_plans', 'installment_rows', 'installment_collections');

            $this->seedMessages($tenantId);
            $this->analyse('messages');

            return [
                'invoices' => $this->countOf('sales_invoices', $tenantId),
                'items' => $this->countOf('sales_invoice_items', $tenantId),
                'movements' => $this->countOf('stock_movements', $tenantId),
                'ledger_entries' => $this->countOf('ledger_entries', $tenantId),
                'parties' => $this->countOf('parties', $tenantId),
                'variants' => $this->countOf('product_variants', $tenantId),
                'units' => $this->countOf('product_units', $tenantId),
                'cheques' => $this->countOf('cheques', $tenantId),
                'installment_rows' => $this->countOf('installment_rows', $tenantId),
                'messages' => $this->countOf('messages', $tenantId),
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

        /*
        | Part-payments against every third invoice — and they are the reason the aging
        | report can be timed at all.
        |
        | With debits only, every party's whole balance is outstanding, `settled` is zero,
        | and the FIFO clamp `least(lot, greatest(cumulative − settled, 0))` collapses to
        | `lot` on every row: the expensive branch never runs, and the payable direction
        | reads an empty set at full speed. The first measurement said exactly that —
        | 84.8ms for receivable against 20.5ms for payable, a gap that was the fixture
        | rather than the query.
        |
        | 71% is deliberate: it settles some lots entirely and leaves one *partially*
        | settled, which is the case the window function exists for.
        */
        DB::insert(<<<'SQL'
            insert into ledger_entries (tenant_id, party_id, account_id, branch_id, debit, credit, reference_type, reference_id, batch_id, description, occurred_at, created_at)
            select
                ?,
                case when side = 1 then i.party_id else null end,
                case when side = 1 then null else ?::bigint end,
                i.branch_id,
                case when side = 1 then 0 else (i.total * 71 / 100) end,
                case when side = 1 then (i.total * 71 / 100) else 0 end,
                'sales_invoice',
                i.id,
                md5('payment:' || i.id::text)::uuid,
                'دریافت بابت ' || i.number,
                i.issued_at + interval '9 days',
                now()
            from sales_invoices i, generate_series(1, 2) side
            where i.tenant_id = ?
              and i.status = 'final'
              and i.total > 0
              and i.party_id is not null
              and i.id % 3 = 0
        SQL, [$tenantId, $structure['sales_account'], $tenantId]);
    }

    /**
     * Handsets in the unit register, so a valuation has both its halves.
     *
     * IMEIs are the ordinal padded to fifteen digits — not valid Luhn, and deliberately
     * not: nothing here validates one, and generating check digits in SQL would be work
     * spent making a timing fixture look like a real phone.
     *
     * Costs vary per row (`9,870,000 + ordinal × 130`) because a valuation groups by
     * product and sums the cost of each device. One repeated figure would let a plan that
     * read a single row and multiplied look identical to one that read five thousand.
     *
     * @param  array{warehouses: list<int>, ...}  $structure
     */
    private function seedUnits(int $tenantId, array $structure): void
    {
        $warehouses = '{'.implode(',', $structure['warehouses']).'}';
        $start = CarbonImmutable::parse(self::YEAR_END)->subDays(self::DAYS - 1)->startOfDay();

        DB::insert(<<<'SQL'
            insert into product_units (
                tenant_id, product_variant_id, warehouse_id, imei1, status, condition,
                cost, acquired_at, created_at, updated_at
            )
            select
                ?,
                v.id,
                (?::bigint[])[1 + (n % array_length(?::bigint[], 1))],
                lpad((350000000000000 + n)::text, 15, '0'),
                -- Four in five still on the shelf. The sold ones matter: `valuation`
                -- filters on status, and a fixture where every row qualifies never
                -- exercises that predicate.
                case when n % 5 = 0 then 'sold' else 'in_stock' end,
                'new',
                9870000 + (n * 130),
                ?::timestamp + make_interval(days => n % ?),
                now(),
                now()
            from generate_series(1, ?) n
            join lateral (
                select id from product_variants
                where tenant_id = ?
                order by id
                offset (n % ?) limit 1
            ) v on true
        SQL, [
            $tenantId, $warehouses, $warehouses,
            $start->toDateTimeString(), self::DAYS,
            self::UNITS, $tenantId, self::VARIANTS,
        ]);
    }

    /**
     * A year of paper, in both directions and across the lifecycle.
     *
     * The status spread is the point. `chequeCalendar()` sums open cheques into the net and
     * cleared ones into their own column, so a fixture that was all `in_hand` would never
     * touch the `case` branches the report is made of — and one that was all `cleared`
     * would report a net of zero at full speed.
     *
     * Amounts are multiples of ten (`% 10 = 0`), which the table's CHECK enforces: ADR 0009
     * says a cheque's face value is a whole number of toman or the receipt cannot print.
     *
     * @param  array{branches: list<int>, ...}  $structure
     */
    private function seedCheques(int $tenantId, array $structure): void
    {
        $branches = '{'.implode(',', $structure['branches']).'}';
        $start = CarbonImmutable::parse(self::YEAR_END)->subDays(self::DAYS - 1)->startOfDay();

        DB::insert(<<<'SQL'
            insert into cheques (
                tenant_id, branch_id, direction, status, party_id, amount,
                bank_name, serial, due_date, created_at, updated_at
            )
            select
                ?,
                (?::bigint[])[1 + (n % array_length(?::bigint[], 1))],
                case when n % 3 = 0 then 'issued' else 'received' end,
                (array['in_hand', 'deposited', 'cleared', 'bounced', 'returned'])[1 + (n % 5)],
                p.id,
                -- Ends in a zero, never in a round million: the whole-toman CHECK is
                -- satisfied and the sums still have to carry real digits.
                (12000000 + (n * 7310))::bigint,
                (array['ملت', 'صادرات', 'ملی', 'پاسارگاد', 'سامان'])[1 + (n % 5)],
                'S' || lpad(n::text, 8, '0'),
                (?::timestamp + make_interval(days => n % ?))::date,
                now(),
                now()
            from generate_series(1, ?) n
            join lateral (
                select id from parties
                where tenant_id = ?
                order by id
                offset (n % ?) limit 1
            ) p on true
        SQL, [
            $tenantId, $branches, $branches,
            $start->toDateTimeString(), self::DAYS,
            self::CHEQUES, $tenantId, self::PARTIES,
        ]);
    }

    /**
     * Instalment contracts, their schedules, and part-collections against some of them.
     *
     * Three inserts because the book is a three-table join and each level has to have real
     * cardinality: 1,000 plans × 6 rows is 6,000 instalments, and the collections are
     * deliberately **sparse and partial** — roughly a third of rows, none of them settling
     * the row in full. A fixture where every row is either untouched or exactly paid never
     * exercises `amount - unapplied` or the `outstanding > 0` branch that decides what is
     * overdue.
     *
     * @param  array{branches: list<int>, sales_account: int, ...}  $structure
     */
    private function seedInstallments(int $tenantId, array $structure): void
    {
        $branches = '{'.implode(',', $structure['branches']).'}';
        $start = CarbonImmutable::parse(self::YEAR_END)->subDays(self::DAYS - 1)->startOfDay();

        DB::insert(<<<'SQL'
            insert into installment_plans (
                tenant_id, branch_id, party_id, number, principal, profit_amount,
                total_payable, installment_count, interval_months, first_due_at,
                status, created_at, updated_at
            )
            select
                ?,
                (?::bigint[])[1 + (n % array_length(?::bigint[], 1))],
                p.id,
                'INS-VOL-' || lpad(n::text, 6, '0'),
                (48000000 + (n * 9130))::bigint,
                (7200000 + (n * 1370))::bigint,
                (55200000 + (n * 10500))::bigint,
                ?,
                1,
                ?::timestamp + make_interval(days => n % ?),
                case when n % 7 = 0 then 'settled' else 'active' end,
                now(),
                now()
            from generate_series(1, ?) n
            join lateral (
                select id from parties
                where tenant_id = ?
                order by id
                offset (n % ?) limit 1
            ) p on true
        SQL, [
            $tenantId, $branches, $branches,
            self::PLAN_ROWS, $start->toDateTimeString(), self::DAYS,
            self::PLANS, $tenantId, self::PARTIES,
        ]);

        DB::insert(<<<'SQL'
            insert into installment_rows (
                tenant_id, installment_plan_id, sequence, due_at, amount, status,
                created_at, updated_at
            )
            select
                ?,
                pl.id,
                seq,
                pl.first_due_at + make_interval(days => (seq - 1) * 30),
                (9200000 + (pl.id % 500) * 1310)::bigint,
                case when seq = 1 then 'paid' else 'pending' end,
                now(),
                now()
            from installment_plans pl, generate_series(1, ?) seq
            where pl.tenant_id = ?
              and pl.number like 'INS-VOL-%'
        SQL, [$tenantId, self::PLAN_ROWS, $tenantId]);

        DB::insert(<<<'SQL'
            insert into installment_collections (
                tenant_id, branch_id, installment_row_id, installment_plan_id, account_id,
                amount, principal_part, unapplied, method, occurred_at, created_at, updated_at
            )
            select
                ?,
                (?::bigint[])[1 + (r.id % array_length(?::bigint[], 1))],
                r.id,
                r.installment_plan_id,
                ?,
                -- `/ 30 * 10`, not `/ 3`: a third of a whole-toman amount is generally
                -- NOT a whole toman, and `amount - collected` then lands on a rial
                -- figure this product refuses to display. `Money::inUnit()` throws
                -- rather than rounding a customer's money away (golden rule 2), so the
                -- dashboard 500s on a fixture that looked fine for months — the only
                -- consumer was a latency test that never formatted what it summed.
                ((r.amount / 30)::bigint * 10),
                ((r.amount / 30)::bigint * 10),
                0,
                'cash',
                r.due_at,
                now(),
                now()
            from installment_rows r
            join installment_plans pl on pl.id = r.installment_plan_id
            where r.tenant_id = ?
              and pl.number like 'INS-VOL-%'
              and r.id % 3 = 0
        SQL, [$tenantId, $branches, $branches, $structure['sales_account'], $tenantId]);
    }

    /**
     * A year of automations firing, spread over the templates a shop actually runs.
     *
     * Segments vary with the template because that is the figure the report exists to
     * surface: a Persian SMS is 70 characters per segment, so a template that grew by a
     * word doubles the bill on everything it sends, and a fixture with one segment per
     * message would make the sum and the count the same query.
     */
    private function seedMessages(int $tenantId): void
    {
        $start = CarbonImmutable::parse(self::YEAR_END)->subDays(self::DAYS - 1)->startOfDay();

        DB::insert(<<<'SQL'
            insert into messages (
                tenant_id, "to", template_key, status, segments, cost, body,
                queued_at, sent_at, created_at, updated_at
            )
            select
                ?,
                '+98912' || lpad((n % 9999999)::text, 7, '0'),
                (array['installment-due', 'repair-ready', 'birthday', 'cheque-due', 'invoice-issued'])[1 + (n % 5)],
                -- Mostly sent, with real failures and opt-outs among them: the report
                -- counts the three apart, and a fixture of pure successes would leave two
                -- of the three `filter (where …)` clauses measuring nothing.
                (array['sent', 'sent', 'sent', 'failed', 'suppressed'])[1 + (n % 5)],
                1 + (n % 3),
                (145000 * (1 + (n % 3)))::bigint,
                'متن پیام آزمایشی',
                ?::timestamp + make_interval(days => n % ?),
                ?::timestamp + make_interval(days => n % ?),
                now(),
                now()
            from generate_series(1, ?) n
        SQL, [
            $tenantId,
            $start->toDateTimeString(), self::DAYS,
            $start->toDateTimeString(), self::DAYS,
            self::MESSAGES,
        ]);

        DB::insert(<<<'SQL'
            insert into sms_credit_entries (tenant_id, amount, type, description, occurred_at, created_at)
            select
                ?,
                case when n % 12 = 0 then 50000000 else -(2450000 + n * 130) end,
                case when n % 12 = 0 then 'topup' else 'charge' end,
                'شارژ/مصرف حجمی',
                ?::timestamp + make_interval(days => n % ?),
                now()
            from generate_series(1, 365) n
        SQL, [$tenantId, $start->toDateTimeString(), self::DAYS]);
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
