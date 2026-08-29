<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The moments that matter: warned, blocked, upgraded after being blocked.
 *
 * Not an audit of every increment — that is what `usage_counters.used` is for, and a row
 * per invoice would be a second, larger copy of the sales table. This records the handful
 * of events per shop per period that carry commercial meaning, which makes it the first
 * real pricing signal the product will have: **which limit sells upgrades, and which one
 * only annoys people**. A shop blocked on `sales.invoices` that upgrades within the week
 * is a limit doing its job; one blocked on `reporting.exports` that never comes back is a
 * limit costing us a customer, and there is no way to tell them apart without this table.
 *
 * ## The unique index is the idempotency
 *
 * `(tenant_id, metric, period_key, kind)` means one warning and one block per credit per
 * period. A shop that crosses 80% and then does ninety more things must not be told ninety
 * times, and two workers crossing the line in the same second must not both write. Doing
 * that in PHP would need a memo that is wrong across processes; the index is right
 * everywhere, and the writers catch 23505 and move on (the savepoint rule — the `try` goes
 * outside the `DB::transaction`, per CLAUDE.md).
 *
 * `kind` is the only thing that varies per period, so a shop that is warned and then
 * blocked writes two rows, which is what the funnel needs.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('metric', 48);
            $table->string('kind', 16)->comment('warning | blocked | bulk_blocked | upgraded_after');
            $table->char('period_key', 10);

            $table->unsignedBigInteger('used');

            // Null = the shop was unlimited on this metric. Kept rather than skipped
            // because an `upgraded_after` row is written against the plan the shop moved
            // TO, which may well be unlimited.
            $table->unsignedBigInteger('limit_value')->nullable();

            // How much they were trying to do when we stopped them. The difference
            // between "wanted one more invoice" and "tried to import four hundred
            // products" is the difference between a nudge and a wrong-sized plan.
            $table->unsignedInteger('requested')->default(1);

            $table->string('plan_code', 32);

            // Deliberately loose — no foreign key. `users` is tenant-scoped and RLS-
            // protected, this table is read across tenants by the panel, and a real FK
            // would either fight that or delete history when a shop removes an employee.
            // The id is for support ("who hit it"), not for a join.
            $table->unsignedBigInteger('user_id')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['tenant_id', 'metric', 'period_key', 'kind'],
                'usage_events_once_per_period'
            );

            // Per-shop history, for the tenant usage page.
            $table->index(['tenant_id', 'created_at'], 'usage_events_tenant_time_idx');

            // "Which limit blocked the most shops this week" — the platform question,
            // asked across tenants inside runAsPlatform(). Deliberately does NOT lead
            // with tenant_id, and does not need to: `tenancy:check` requires *an* index
            // leading with tenant_id, which the one above is.
            $table->index(['kind', 'created_at'], 'usage_events_kind_time_idx');
        });

        $this->enableRls('usage_events', allowPlatform: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
