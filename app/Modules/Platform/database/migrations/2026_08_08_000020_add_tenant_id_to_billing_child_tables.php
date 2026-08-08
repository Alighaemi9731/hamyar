<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Close the RLS gap on the two billing child tables (Gate 2, item 0).
 *
 * `subscription_addons` and `payment_attempts` were created carrying only a foreign key
 * to their parent. That made them invisible to `tenancy:check`, which keys off a
 * non-nullable `tenant_id`, so they had no policy at all. The defence was "they are only
 * reachable through an RLS-protected parent" — true today, and false the moment Phase 2.4
 * adds a tenant-facing billing endpoint that queries them by their own id. An assumption
 * that holds only until the next feature is not a security boundary.
 *
 * So they get the same treatment as their parents: a denormalised `tenant_id`, a
 * composite index leading with it, and FORCE RLS with the `app.platform` disjunct. The
 * denormalisation is deliberate — a policy that joined the parent would run on every row
 * of every query, and Postgres cannot use an index for it.
 *
 * Written as a separate migration rather than an edit to 000010 because these tables may
 * already hold rows: the backfill below derives each `tenant_id` from the parent, and the
 * column only becomes NOT NULL once every row has one.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::table('subscription_addons', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Backfill from the parent. Runs as the migration role, before any policy exists
        // on these tables, so it sees every row regardless of tenant context.
        DB::statement('
            update subscription_addons as a
               set tenant_id = s.tenant_id
              from subscriptions as s
             where s.id = a.subscription_id
        ');

        DB::statement('
            update payment_attempts as p
               set tenant_id = i.tenant_id
              from subscription_invoices as i
             where i.id = p.subscription_invoice_id
        ');

        // An orphan here would silently become a row no tenant can ever see, so fail the
        // migration instead. Both parents are cascade-delete, so this should be empty.
        foreach (['subscription_addons', 'payment_attempts'] as $table) {
            $orphans = DB::table($table)->whereNull('tenant_id')->count();

            if ($orphans > 0) {
                throw new RuntimeException(
                    "Cannot make {$table}.tenant_id NOT NULL: {$orphans} row(s) have no reachable parent."
                );
            }
        }

        Schema::table('subscription_addons', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            $table->index(['tenant_id', 'subscription_id']);
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
            $table->index(['tenant_id', 'status']);
        });

        // Platform-owned like their parents: a shop must never write its own add-on or
        // payment attempt, and the Filament panel reads both across tenants.
        $this->enableRls('subscription_addons', allowPlatform: true);
        $this->enableRls('payment_attempts', allowPlatform: true);
    }

    public function down(): void
    {
        $this->disableRls('subscription_addons');
        $this->disableRls('payment_attempts');

        Schema::table('subscription_addons', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'subscription_id']);
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
