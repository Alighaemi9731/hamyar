<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Counters are per branch as well as per tenant (docs/specs/settings.md).
 *
 * The table shipped in Phase 2.4 with `(tenant_id, key, period)` because the only caller
 * then was subscription invoicing, which is tenant-level. Branches now exist, and Phase 5
 * will number sales invoices — where two shopfronts sharing one sequence is wrong twice
 * over: the numbers interleave unpredictably, and Iranian tax practice expects each
 * registered location's invoices to run contiguously.
 *
 * Fixed now rather than in Phase 5, because after the first sales invoice is issued this
 * becomes a data migration on live numbering instead of an index change on an empty
 * column.
 *
 * `branch_id` is nullable: tenant-level counters (subscription invoices) legitimately
 * have no branch, and NULL is the natural way to say "the whole shop".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('counters', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('tenant_id')->constrained()->cascadeOnDelete();
        });

        // Postgres treats every NULL as distinct in a unique index, so the old constraint
        // would stop enforcing anything at all for tenant-level counters once a nullable
        // column joined it. NULLS NOT DISTINCT makes NULL behave like a value here, which
        // is exactly what "no branch" means for this key.
        DB::statement('alter table counters drop constraint if exists counters_tenant_id_key_period_unique');

        DB::statement(
            'create unique index counters_scope_unique
             on counters (tenant_id, key, branch_id, period) nulls not distinct'
        );

        Schema::table('counters', function (Blueprint $table): void {
            $table->index(['tenant_id', 'branch_id', 'key']);
        });
    }

    public function down(): void
    {
        DB::statement('drop index if exists counters_scope_unique');

        Schema::table('counters', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'branch_id', 'key']);
            $table->dropConstrainedForeignId('branch_id');
            $table->unique(['tenant_id', 'key', 'period']);
        });
    }
};
