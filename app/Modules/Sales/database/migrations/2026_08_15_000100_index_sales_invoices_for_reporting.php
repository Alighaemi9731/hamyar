<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index every dated sales report was missing, found by measuring rather than reading.
 *
 * ## What the plan said
 *
 * Every report in `SalesReports` — and `ProfitEngine::forPeriod()` under them — filters
 * the same three things: this shop, `status = 'final'`, and a date range. The schema had
 * `(tenant_id, status)` and `(tenant_id, branch_id, issued_at)`, and neither serves that:
 * the first stops before the range, and the second cannot be entered on `issued_at`
 * without a branch, which the consolidated view deliberately does not supply.
 *
 * So Postgres took `(tenant_id, status)`, walked **every final invoice the shop has ever
 * issued**, and threw away the ones outside the month in a heap filter. On the 100k-row
 * fixture (`BulkVolumeSeeder`), a thirty-day report read 75,200 index entries and
 * fetched 12,533 heap rows to keep 3,093:
 *
 *   Bitmap Index Scan on sales_invoices_tenant_id_status_index (rows=75200)
 *     Filter: issued_at >= … AND issued_at <= …
 *     Rows Removed by Filter: 11502
 *
 * With the range in the index that becomes 6,186 entries for the same 3,093 rows and no
 * filter at all. The invoice-side cost stops growing with the shop's whole history and
 * starts growing with the length of the range being asked about, which is the only one
 * of the two a shopkeeper chose.
 *
 * ## What it is worth today, stated honestly
 *
 * Twelve milliseconds against fourteen, on the same nested-loop plan. Both are an order
 * of magnitude inside the 300ms budget, and at this fixture size the planner in fact
 * often prefers a hash join that scans the items table instead — a different history
 * term, one level down, and one this index cannot address.
 *
 * The index is here for the shape rather than for the two milliseconds: a term
 * proportional to everything the shop has ever sold does not stay small, and the report
 * it slows down is the one that gets opened every morning. The measurement is recorded
 * so the next person does not have to re-derive it, and so nobody reads the two
 * milliseconds as the claim.
 *
 * ## Why it replaces the old index rather than joining it
 *
 * `(tenant_id, status, issued_at)` answers everything `(tenant_id, status)` answered — a
 * leading prefix of a composite is still an index — and additionally carries the range.
 * Keeping both would pay for two index writes on every insert to buy nothing.
 *
 * The branch index stays. `(tenant_id, branch_id, issued_at)` is what the per-branch cut
 * needs, and this one cannot serve it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status', 'issued_at'], 'sales_invoices_tenant_status_issued_index');
            $table->dropIndex('sales_invoices_tenant_id_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status'], 'sales_invoices_tenant_id_status_index');
            $table->dropIndex('sales_invoices_tenant_status_issued_index');
        });
    }
};
