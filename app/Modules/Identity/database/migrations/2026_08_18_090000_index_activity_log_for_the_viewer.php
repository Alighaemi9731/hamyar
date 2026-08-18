<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes matched to the audit-log viewer's filter set (11c).
 *
 * The table arrived with spatie's two morph indexes, `subject (subject_type,
 * subject_id)` and `causer (causer_type, causer_id)`, plus `(tenant_id, created_at)`
 * from the tenancy migration. Neither morph index leads with `tenant_id`, and **every**
 * query this viewer makes carries a tenant predicate, because the RLS policy adds one
 * whether the query does or not.
 *
 * That is fine at the volume the log holds today and stops being fine at the volume it
 * is heading for: fifty shops of history rather than a test suite's worth. Filtering to
 * «همه‌ی تغییرات کالاها» would seek on `subject_type` across every shop's rows and let
 * the policy discard the rest — work proportional to the whole platform to answer a
 * question about one shop.
 *
 * Two indexes, chosen against the five filter shapes the screen can produce:
 *
 * | filter                             | served by                                     |
 * |------------------------------------|-----------------------------------------------|
 * | none / date range only             | `(tenant_id, created_at)` — already there      |
 * | one record («تاریخچه» from a page)  | `activity_log_tenant_subject_index`            |
 * | a kind of record                   | `activity_log_tenant_subject_index` (prefix)   |
 * | an actor                           | `activity_log_tenant_causer_index`             |
 * | free text                          | none — see the note below                      |
 *
 * `subject_id` sits before `created_at` so the single-record lookup — the hot path,
 * because it is what the «تاریخچه» link on a product page does — is one seek returning
 * rows already in order. A `subject_type`-only filter uses the same index as a prefix
 * and sorts afterwards, which is the right trade: that query is rarer and its result
 * set is one page.
 *
 * **Free text is deliberately left unindexed.** The fix would be a `pg_trgm` GIN index
 * on `description`, and `CREATE EXTENSION` needs superuser — a migration that cannot
 * run as the application role is a deploy that fails on the day it matters, to make a
 * rare filter faster. It always runs with the tenant predicate applied, so the scan is
 * bounded by one shop's history rather than the platform's. Measured, not assumed —
 * the numbers are in ADR 0014.
 *
 * The vendor morph indexes stay. They are the only ones that serve the central rows,
 * where `tenant_id IS NULL` and a tenant-leading index is no help at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->index(
                ['tenant_id', 'subject_type', 'subject_id', 'created_at'],
                'activity_log_tenant_subject_index',
            );

            // `causer_id` without `causer_type`: within one tenant the causer is a
            // User or nothing, and carrying the type would add 30-odd bytes per entry
            // to discriminate a set of one.
            $table->index(
                ['tenant_id', 'causer_id', 'created_at'],
                'activity_log_tenant_causer_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex('activity_log_tenant_subject_index');
            $table->dropIndex('activity_log_tenant_causer_index');
        });
    }
};
