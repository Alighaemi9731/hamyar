<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "I have seen this one on the bank statement."
 *
 * ## Why a column on the entry rather than a balance somewhere
 *
 * Reconciliation is an assertion a person makes about a particular movement, not about a
 * total. When the bank says 41,300,000 and the software says 44,800,000, the useful
 * answer is never "the balance is wrong" — it is "these three entries are the ones
 * nobody has confirmed", and that is only answerable if the fact lives on the row.
 *
 * It also means the unreconciled figure is a SUM over unticked rows, which keeps golden
 * rule 3 intact: no stored total to drift.
 *
 * ## Nullable, and not part of the append-only rule
 *
 * `ledger_entries` is append-only about *money* — a mistake is corrected with an
 * opposite entry, never an edit. This column carries no money. It records that a human
 * looked at a statement and agreed, which is a fact about the paper trail rather than
 * about the books, and un-ticking it is a legitimate thing to do when somebody ticks the
 * wrong line.
 *
 * `reconciled_by` rides along because "who said they had seen it" is the first question
 * asked when it turns out they had not.
 *
 * ## The partial index
 *
 * The query that matters is "what is still unticked on this account", which touches only
 * rows where `reconciled_at is null` — a shrinking minority in a shop that keeps up. A
 * partial index stays small no matter how many years of entries accumulate behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->timestampTz('reconciled_at')->nullable()->after('occurred_at');
            $table->foreignId('reconciled_by')->nullable()->after('reconciled_at')
                ->constrained('users')->nullOnDelete();
        });

        // Leading with tenant_id per golden rule 1, then the account the shopkeeper is
        // staring at. Partial, because a reconciled row is never the subject of this
        // query again.
        \Illuminate\Support\Facades\DB::statement(
            'create index ledger_entries_unreconciled_idx
             on ledger_entries (tenant_id, account_id)
             where reconciled_at is null and account_id is not null'
        );
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('drop index if exists ledger_entries_unreconciled_idx');

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reconciled_by');
            $table->dropColumn('reconciled_at');
        });
    }
};
