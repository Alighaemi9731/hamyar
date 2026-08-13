<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * چک — the paper a large share of this market actually trades on.
 *
 * Built against `docs/specs/cheques.md`, which was written first and which
 * `ChequePostingMatrixTest` pins row-for-row. Read the spec before changing anything here.
 *
 * ## One table, a direction column
 *
 * Received and issued cheques share every field and differ only in which way the money
 * eventually goes. Two tables would give the lifecycle two implementations to drift apart
 * in — the same argument `cash_transactions` makes for expenses and incomes.
 *
 * ## The face value is a fact about paper, and it is never derived
 *
 * ADR 0009 has almost nothing to divide here: no floor-and-carry, no proration. The only
 * binding rule is that the amount is a whole number of toman, because `Money::toArray()`
 * refuses to render anything else — and the refusal would happen on the printed receipt,
 * with the customer watching. Enforced by a CHECK so it cannot be bypassed by a service
 * somebody adds later.
 *
 * ## The Sayad identifier is unique, and that is the important index
 *
 * صیاد numbers are the national cheque registry's identifier and are unique per physical
 * cheque. Entering the same one twice credits a customer twice, and it is discovered
 * months later when a cheque nobody holds fails to clear. The partial unique index is the
 * guarantee; the bank/serial pair backs it up for older paper with no Sayad number.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('cheques', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('direction')->comment('received | issued');
            $table->string('status')->default('in_hand');

            /*
            | The party this cheque is *about*.
            |
            | Received: whoever handed it over — the person whose debt it settled and who
            | owes again if it bounces. Issued: the payee.
            |
            | Required in both directions. A cheque with no party has nobody to credit when
            | it is taken and nobody to chase when it fails.
            */
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();

            // Received + endorsed: who it was passed to. The endorsement chain is what
            // lets a later bounce be traced back to the drawer.
            $table->foreignId('endorsed_to_party_id')->nullable()->constrained('parties')->nullOnDelete();

            /*
            | Received: the bank it was DEPOSITED to, set at deposit and not re-choosable
            | at clearing — otherwise the ledger says money arrived somewhere it did not.
            | Issued: the account it is DRAWN ON, set at issue for the same reason.
            */
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();

            $table->unsignedBigInteger('amount')->comment('Face value in rial');

            // Set when a bank pays part and certifies the rest — گواهینامه عدم پرداخت.
            $table->unsignedBigInteger('recovered_amount')->default(0);

            $table->string('bank_name');
            $table->string('branch_name')->nullable();
            $table->string('serial');
            $table->string('sayad_id', 16)->nullable()->comment('شناسه صیاد');
            $table->string('account_holder')->nullable()->comment('Whose name is printed on it');

            $table->date('due_date');
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('deposited_at')->nullable();
            $table->timestampTz('cleared_at')->nullable();
            $table->timestampTz('bounced_at')->nullable();

            $table->string('bounce_reason')->nullable();
            $table->unsignedSmallInteger('presentation_attempt')->default(1);

            // What created it, when a sale or a purchase did.
            $table->nullableMorphs('reference');

            $table->text('notes')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'direction', 'status'], 'cheques_tenant_direction_status_idx');
            $table->index(['tenant_id', 'due_date'], 'cheques_tenant_due_idx');
            $table->index(['tenant_id', 'party_id'], 'cheques_tenant_party_idx');
        });

        DB::statement(
            "alter table cheques
             add constraint cheques_direction
             check (direction in ('received', 'issued'))"
        );

        // ADR 0009 — a whole number of toman, or the receipt cannot be printed.
        DB::statement(
            'alter table cheques
             add constraint cheques_amount_whole_toman
             check (amount > 0 and amount % 10 = 0)'
        );

        DB::statement(
            'alter table cheques
             add constraint cheques_recovered_within_face
             check (recovered_amount >= 0 and recovered_amount <= amount)'
        );

        // The same physical cheque, entered twice, credits a customer twice.
        DB::statement(
            "create unique index cheques_sayad_unique
             on cheques (tenant_id, direction, sayad_id)
             where sayad_id is not null and deleted_at is null and status <> 'cancelled'"
        );

        DB::statement(
            "create unique index cheques_bank_serial_unique
             on cheques (tenant_id, direction, bank_name, serial)
             where deleted_at is null and status <> 'cancelled'"
        );

        $this->enableRls('cheques');

        Schema::create('cheque_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cheque_id')->constrained()->cascadeOnDelete();

            $table->string('from_status')->nullable();
            $table->string('to_status');

            // The ledger batch this transition posted, so a statement can show the event
            // as a unit and a correction can find every row it must undo. Null for the
            // transitions that deliberately post nothing.
            $table->uuid('batch_id')->nullable();

            $table->unsignedBigInteger('amount')->default(0)->comment('What this event moved');
            $table->string('note')->nullable();
            $table->timestampTz('occurred_at');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'cheque_id'], 'cheque_events_tenant_cheque_idx');
        });

        $this->enableRls('cheque_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('cheque_events');
        Schema::dropIfExists('cheques');
    }
};
