<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The meter: how much of each credit a shop has spent this period.
 *
 * One row per (tenant, metric, period). The unique index is not a nicety — it is the
 * arbiter of the whole design. `DatabaseQuotaGuard` spends a credit with a single
 * `INSERT … ON CONFLICT (tenant_id, metric, period_key) DO UPDATE … WHERE used + n <=
 * limit`, so two requests racing for the last unit serialise on this index and the second
 * one re-evaluates the cap against the first one's committed value. Drop the index and the
 * statement stops being atomic and starts being a suggestion.
 *
 * ## Why a stored total, when golden rule 3 says write movements
 *
 * Rule 3 governs money and stock, where the total is the truth and must be derivable from
 * its movements — a stock figure that disagrees with the ledger is a bug in the books. A
 * quota meter is a different kind of number: plan-relative, monotone within its period,
 * discarded when the period turns, and never reconciled against anything. Its ledger is
 * `usage_events`, which records the moments that matter (a warning, a block) rather than
 * every increment. Counting rows in the domain tables instead was considered and rejected
 * in ADR 0018: products, parties, units and invoices soft-delete, invoice children are
 * hard-deleted and recreated on every edit, and a voided invoice keeps its row — so a live
 * count would let a shop buy back credit by deleting its own work.
 *
 * ## Platform-owned, like `subscriptions`
 *
 * RLS with the `app.platform` escape, and deliberately NO `BelongsToTenant`: the panel's
 * usage page and the blocked-shops widget read across every shop, and the Eloquent scope
 * adds `1 = 0` when no tenant is pinned — so a trait-scoped model would come back empty
 * inside `runAsPlatform()` and the screen would show nothing while looking like it worked.
 * The trade is that every query here must carry its own `where('tenant_id', …)`, which
 * `bin/check-quota-scoping` makes mechanical rather than a matter of reviewer attention.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('metric', 48);

            // The Gregorian date the shop's Jalali month began on — `2026-08-23` for
            // شهریور ۱۴۰۵. Ten characters, fixed width, sortable, comparable against
            // `date(...)` in SQL, and not a Jalali string in a column (golden rule 5).
            $table->char('period_key', 10);

            $table->unsignedBigInteger('used')->default(0);

            // Denormalised from `usage_events` on purpose: the shared `usage` prop runs on
            // every staff page and needs to know whether this credit has actually stopped
            // the shop — a meter at 100% that never blocked anyone is a shop that used
            // exactly what it paid for, and shouting at it in red would be wrong. Reading
            // that from the events table would be a second query on the hot path.
            $table->timestampTz('blocked_at')->nullable();

            $table->timestampTz('first_used_at')->useCurrent();
            $table->timestampTz('last_used_at')->useCurrent();

            // THE arbiter. See the class docblock.
            $table->unique(['tenant_id', 'metric', 'period_key'], 'usage_counters_scope_unique');

            // "Every meter for this shop, this period" — the shared-prop read. Leads with
            // tenant_id, which `tenancy:check` requires, and covers the columns the prop
            // needs so it stays an index-only scan between vacuums.
            $table->index(['tenant_id', 'period_key'], 'usage_counters_tenant_period_idx');
        });

        $this->enableRls('usage_counters', allowPlatform: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
