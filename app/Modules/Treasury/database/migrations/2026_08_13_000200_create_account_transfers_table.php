<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Banking the day's takings, and every other move of money the shop already has.
 *
 * ## Why a table, when the ledger already records both sides
 *
 * The two ledger rows say a credit happened here and a debit there. They do not say the
 * two were the *same act*. They share a `batch_id`, which is enough to reverse them
 * together and not enough to answer the questions a shopkeeper actually asks: how many
 * transfers went to this bank this month, who authorised the big one, what did the PSP
 * charge us in fees this quarter.
 *
 * This row is the act. The ledger rows are its consequences.
 *
 * ## The fee is stored beside the amount, not folded into it
 *
 * A card settlement of 50,000,000 with a 350,000 charge is not a transfer of
 * 49,650,000 — it is a transfer of 50,000,000 that cost 350,000. Folding them makes the
 * fee invisible to any report and makes the destination balance wrong by exactly the
 * amount nobody can find later.
 *
 * ## `occurred_at` is not `created_at`
 *
 * Cash banked on Thursday gets entered on Saturday. The ledger, the daily close and the
 * cash-flow report all care when the money moved; only an audit trail cares when
 * somebody typed it. Storing one and pretending it is the other is how a Z report stops
 * matching the drawer.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('account_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();

            $table->unsignedBigInteger('amount')->comment('Rial. What arrived at the destination.');
            $table->unsignedBigInteger('fee')->default(0)->comment('Rial. What the bank or PSP took, on top.');

            $table->string('reference')->nullable();
            $table->timestampTz('occurred_at');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'occurred_at'], 'account_transfers_tenant_occurred_idx');
            $table->index(['tenant_id', 'from_account_id'], 'account_transfers_tenant_from_idx');
            $table->index(['tenant_id', 'to_account_id'], 'account_transfers_tenant_to_idx');
        });

        // Nothing moves to where it already is. A mis-click otherwise writes two rows
        // that cancel out and explain nothing, and the database is a better place to
        // refuse it than a service somebody can bypass.
        DB::statement(
            'alter table account_transfers
             add constraint account_transfers_distinct_accounts
             check (from_account_id <> to_account_id)'
        );

        DB::statement(
            'alter table account_transfers
             add constraint account_transfers_positive_amount
             check (amount > 0)'
        );

        $this->enableRls('account_transfers');
    }

    public function down(): void
    {
        Schema::dropIfExists('account_transfers');
    }
};
