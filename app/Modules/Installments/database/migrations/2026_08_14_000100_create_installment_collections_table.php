<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every payment taken against an instalment.
 *
 * ## Why a table rather than a `collected_amount` column
 *
 * Golden rule 3. What is outstanding on a row is `amount - SUM(collections)`, never a
 * figure somebody increments — for the same reason stock and party balances are sums. A
 * stored total drifts the first time a job dies between the ledger post and the update,
 * and a customer arguing about how much is left on their instalment is the last person a
 * shop wants to have that conversation with.
 *
 * It is also the only shape that answers the questions people actually ask: «چقدر از این
 * قسط رو داده؟», «کی داد؟», «با چی داد؟» — three payments over five weeks, each with its
 * own date and method, is an ordinary way for an instalment to be settled in this market.
 *
 * ## The split is stored, and that is not a contradiction
 *
 * `fee_part`, `profit_part` and `principal_part` record how *this* payment was applied,
 * under the fee-then-profit-then-principal order in `docs/specs/installment-collection.md`
 * §4. They are not a balance: they are a fact about one event, decided once, the same way
 * `cost_snapshot` is. Recomputing the split later from a changed fee policy would restate
 * a receipt the customer is holding.
 *
 * ## `occurred_at` is when the money arrived
 *
 * Not when it was keyed. The daily close and the cash-flow report both group by it, and a
 * payment taken on Thursday and entered on Saturday belongs to Thursday or the Z report
 * for Thursday is wrong forever.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('installment_collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('installment_row_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_plan_id')->constrained()->cascadeOnDelete();

            // Where the money landed.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->unsignedBigInteger('amount')->comment('Rial, what was handed over');

            // How this payment was applied — fee, then profit, then principal.
            $table->unsignedBigInteger('fee_part')->default(0);
            $table->unsignedBigInteger('profit_part')->default(0);
            $table->unsignedBigInteger('principal_part')->default(0);
            $table->unsignedBigInteger('unapplied')->default(0)->comment('Overpayment, left on the party as credit');

            $table->string('method')->default('cash')->comment('Mirrors Sales PaymentMethod');
            $table->string('reference')->nullable();

            $table->timestampTz('occurred_at');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'occurred_at'], 'installment_collections_tenant_occurred_idx');
            $table->index(['tenant_id', 'installment_row_id'], 'installment_collections_tenant_row_idx');
            $table->index(['tenant_id', 'installment_plan_id'], 'installment_collections_tenant_plan_idx');
        });

        DB::statement(
            'alter table installment_collections
             add constraint installment_collections_positive_amount
             check (amount > 0)'
        );

        // The parts must account for the whole payment. A row where they do not is a
        // receipt that cannot be explained to the person holding it.
        DB::statement(
            'alter table installment_collections
             add constraint installment_collections_parts_sum
             check (fee_part + profit_part + principal_part + unapplied = amount)'
        );

        $this->enableRls('installment_collections');

        Schema::table('installment_rows', function (Blueprint $table): void {
            // When it was fully settled. Nullable, and derived state — the authority on
            // "is this paid" is still the sum of collections; this is for display and for
            // the overdue sweep, which should not aggregate on every row it scans.
            $table->timestampTz('settled_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('installment_rows', function (Blueprint $table): void {
            $table->dropColumn('settled_at');
        });

        Schema::dropIfExists('installment_collections');
    }
};
