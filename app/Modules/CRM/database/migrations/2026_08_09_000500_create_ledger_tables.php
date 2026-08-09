<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The balance engine (golden rule 3).
 *
 * A party's balance and an account's balance are both `SUM(debit) - SUM(credit)` over
 * this table. Neither is ever a stored column, for the same reason stock quantity is
 * not: a stored total drifts, and once it has drifted there is nothing to reconcile it
 * against. A shop arguing with its own software about how much a customer owes is a
 * shop that stops trusting the software.
 *
 * Double entry, lightly. Every financial event writes at least two rows that sum to
 * zero — cash received from a customer debits the cash account and credits the party.
 * The `CHECK` below enforces the shape of each row; the *balancing* of a set is enforced
 * by `LedgerService`, which is the only thing that writes here.
 *
 * `debit` and `credit` are separate unsigned columns rather than one signed amount.
 * That is the accounting convention Iranian bookkeepers expect to see on a statement,
 * and printing a signed column as two would mean deciding the sign convention twice.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('type')->default('cash')->comment('cash | bank | pos_terminal | other');

            // Iranian specifics for bank and کارتخوان accounts; null for a cash drawer.
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('iban', 26)->nullable();
            $table->string('terminal_number')->nullable();

            $table->bigInteger('opening_balance')->default(0)->comment('Rial, signed');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'is_active']);
        });

        $this->enableRls('accounts');

        DB::statement(
            'create unique index accounts_one_default_per_tenant
             on accounts (tenant_id) where is_default and deleted_at is null'
        );

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Both nullable, at least one required (enforced below). A sale debits the
            // party with no account involved; a cash transfer between drawers touches
            // accounts with no party.
            $table->foreignId('party_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('debit')->default(0);
            $table->unsignedBigInteger('credit')->default(0);

            // The document this entry explains. Every entry has one in practice; it is
            // nullable only for the opening-balance entries written at onboarding.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // Groups the rows of one financial event, so a statement can show "this
            // payment" as a unit and a reversal can find every row it must undo.
            $table->uuid('batch_id');

            $table->string('description')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('occurred_at')->useCurrent();

            // Append-only, like every ledger here. A mistake is reversed by writing the
            // opposite entry, never by editing this one.
            $table->timestamp('created_at')->useCurrent();

            // The covering index for the balance SUM.
            $table->index(['tenant_id', 'party_id', 'occurred_at'], 'ledger_entries_party_balance');
            $table->index(['tenant_id', 'account_id', 'occurred_at'], 'ledger_entries_account_balance');
            $table->index(['reference_type', 'reference_id']);
            $table->index('batch_id');
        });

        $this->enableRls('ledger_entries');

        // An entry that touches neither a party nor an account explains nothing and
        // cannot appear on any statement — it is invisible money.
        DB::statement(
            'alter table ledger_entries add constraint ledger_entries_has_a_subject
             check (party_id is not null or account_id is not null)'
        );

        // Exactly one side. A row with both debit and credit set is two events wearing
        // one row, and it makes the running balance on a statement unreadable.
        DB::statement(
            'alter table ledger_entries add constraint ledger_entries_one_side_only
             check ((debit > 0 and credit = 0) or (credit > 0 and debit = 0))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('accounts');
    }
};
