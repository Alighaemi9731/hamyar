<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\BulkVolumeSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Every rial the load fixture invents must be a whole number of toman.
 *
 * ## Why this test exists
 *
 * `BulkVolumeSeeder` splits an instalment row into thirds with `amount / 3`. A third of a
 * whole-toman amount is generally **not** a whole toman, so `amount - collected` landed on
 * a rial figure this product refuses to display — `Money::inUnit()` throws rather than
 * rounding a customer's money away (golden rule 2).
 *
 * The dashboard 500s on it. 26,720 of 40,000 seeded collections were affected.
 *
 * **It went unnoticed for months because of who was reading the fixture.** Its only
 * consumer was `ReportLatencyTest`, which calls report *services* and asserts a clock —
 * services return integers, and integers never pass through `Money::format()`. The
 * fixture was producing money the product considers impossible, and the one test looking
 * at it had no reason to look at that.
 *
 * It surfaced the first time an *endpoint* was pointed at the fixture, under 11.2's load
 * test, because a page renders what a service merely returns.
 *
 * ## The rule being asserted
 *
 * A fixture is allowed to invent arithmetic. It is not allowed to invent arithmetic the
 * product's own invariants reject — that produces failures which look like product bugs
 * and are not, which is the most expensive kind of false alarm.
 */
it('invents no money the product would refuse to display', function (): void {
    $tenant = Tenant::factory()->create();

    // A small fill: the invariant is per-row and does not need volume to be violated.
    (new BulkVolumeSeeder)->fill($tenant, 60);

    /*
    | Every money column the seeder writes, and the derived sums that broke.
    |
    | `installment_collections` is checked as the SUM of its three parts rather than
    | column by column, because that is the figure `outstandingOn()` subtracts — the
    | parts could each be whole toman while their total was not.
    */
    $checks = [
        'sales_invoice_items.unit_price' => 'select count(*) from sales_invoice_items where tenant_id = ? and unit_price % 10 <> 0',
        'sales_invoice_items.line_total' => 'select count(*) from sales_invoice_items where tenant_id = ? and line_total % 10 <> 0',
        'sales_invoice_items.cost_snapshot' => 'select count(*) from sales_invoice_items where tenant_id = ? and cost_snapshot % 10 <> 0',
        'sales_invoices.total' => 'select count(*) from sales_invoices where tenant_id = ? and total % 10 <> 0',
        'sales_invoices.paid_total' => 'select count(*) from sales_invoices where tenant_id = ? and paid_total % 10 <> 0',
        'installment_rows.amount' => 'select count(*) from installment_rows where tenant_id = ? and amount % 10 <> 0',
        'installment_collections.parts' => 'select count(*) from installment_collections where tenant_id = ? and (fee_part + profit_part + principal_part) % 10 <> 0',
        'installment_rows.outstanding' => <<<'SQL'
            select count(*)
            from installment_rows r
            left join lateral (
                select coalesce(sum(fee_part + profit_part + principal_part), 0) as settled
                from installment_collections c
                where c.installment_row_id = r.id
            ) c on true
            where r.tenant_id = ? and (r.amount - c.settled) % 10 <> 0
        SQL,
        'cheques.amount' => 'select count(*) from cheques where tenant_id = ? and amount % 10 <> 0',
    ];

    $offenders = [];

    foreach ($checks as $label => $sql) {
        $bad = app(TenantContext::class)->runFor($tenant, function () use ($sql, $tenant): int {
            $row = DB::selectOne($sql, [$tenant->getKey()]);

            return is_object($row) && property_exists($row, 'count') ? (int) $row->count : 0;
        });

        if ($bad > 0) {
            $offenders[$label] = $bad;
        }
    }

    expect($offenders)->toBe(
        [],
        'These fixture columns hold rial that is not a whole number of toman, which the '
        .'product refuses to display: '.json_encode($offenders),
    );
});
