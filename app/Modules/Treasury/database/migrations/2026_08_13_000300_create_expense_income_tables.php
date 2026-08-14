<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money in and out that is not a sale.
 *
 * Rent, wages, tea, the electricity bill, the municipality's shop-front fee — and on the
 * other side, rent collected from a leased desk or a commission from another shop. For a
 * mobile-phone shop these are most of the difference between turnover and what the owner
 * actually keeps, which is why a P&L without them is a number nobody believes.
 *
 * ## One table, a direction column — not two mirror tables
 *
 * An expense and an income are the same event with the arrows reversed: a party, a
 * category, an account, an amount, a date. Two tables would mean two services, two
 * screens, two sets of tests and two places for the posting to drift apart — and the
 * drift would be invisible, because nobody diffs an income service against an expense one.
 *
 * The same argument the cheques module makes for `direction` on one table, and the reason
 * `PaymentMethod` is one enum rather than a class per method.
 *
 * ## Categories are a tree, and each one owns an account
 *
 * A shop wants «هزینه‌ها ← حقوق ← فروشنده‌ها», not a flat list of forty headings. The tree
 * is `parent_id` on the same table; two levels is what shops actually use and nothing
 * enforces a depth limit beyond a guard against a category being its own ancestor.
 *
 * Each category carries an `account_id`. That is what makes a P&L by category a set of
 * account balances rather than a second aggregation over a different table — one source
 * of truth, and the category breakdown reconciles against the ledger by construction
 * rather than by a join somebody has to keep correct.
 *
 * ## `occurred_at` is when the money moved
 *
 * Rent paid on the 1st and entered on the 5th belongs to the 1st. Every report in Phase 7
 * groups by this column and none of them care when somebody typed it — the same rule as
 * `account_transfers`, and the same failure if it is broken: a daily close that does not
 * match the drawer.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('transaction_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('parent_id')->nullable()->constrained('transaction_categories')->cascadeOnDelete();

            // The account every transaction in this category posts to. Created with the
            // category, so a P&L breakdown is a set of balances rather than a second
            // aggregation that has to be kept in step with the first.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->string('name');
            $table->string('direction')->comment('expense | income');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'direction'], 'transaction_categories_tenant_direction_idx');
            $table->index(['tenant_id', 'parent_id'], 'transaction_categories_tenant_parent_idx');
        });

        DB::statement(
            "alter table transaction_categories
             add constraint transaction_categories_direction
             check (direction in ('expense', 'income'))"
        );

        // A category cannot be its own parent. Deeper cycles are caught in the service —
        // a CHECK cannot walk a tree — but the one-step case is the one a mis-click makes.
        DB::statement(
            'alter table transaction_categories
             add constraint transaction_categories_not_self_parent
             check (parent_id is null or parent_id <> id)'
        );

        $this->enableRls('transaction_categories');

        Schema::create('cash_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('transaction_category_id')->constrained()->restrictOnDelete();

            // Who it was paid to, or received from. Optional: the electricity bill has no
            // party worth creating, but the landlord does.
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();

            // Where the money came from or went to. Always a money-holding account.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->string('direction')->comment('expense | income');
            $table->unsignedBigInteger('amount')->comment('Rial, always positive — direction carries the sign');

            $table->string('description')->nullable();
            $table->string('reference')->nullable();

            // Set when generated from a template or a rental contract, so a re-run can
            // see what it already produced. Null for anything a person entered by hand.
            $table->string('generated_key')->nullable();

            $table->timestampTz('occurred_at');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'occurred_at'], 'cash_transactions_tenant_occurred_idx');
            $table->index(['tenant_id', 'direction', 'occurred_at'], 'cash_transactions_tenant_dir_occurred_idx');
            $table->index(['tenant_id', 'transaction_category_id'], 'cash_transactions_tenant_category_idx');
        });

        DB::statement(
            "alter table cash_transactions
             add constraint cash_transactions_direction
             check (direction in ('expense', 'income'))"
        );

        DB::statement(
            'alter table cash_transactions
             add constraint cash_transactions_positive_amount
             check (amount > 0)'
        );

        /*
        | The idempotency key for anything generated rather than typed.
        |
        | A recurring template that runs twice must not book August's rent twice, and the
        | only place that guarantee can live is the database — a service that checks and
        | then inserts loses to a second worker between the two. Same design as the
        | abandoned-device sweep in Phase 6: the record is written under a unique index
        | first, and a collision is the signal that somebody already did it.
        */
        DB::statement(
            'create unique index cash_transactions_generated_once
             on cash_transactions (tenant_id, generated_key)
             where generated_key is not null and deleted_at is null'
        );

        $this->enableRls('cash_transactions');
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('transaction_categories');
    }
};
