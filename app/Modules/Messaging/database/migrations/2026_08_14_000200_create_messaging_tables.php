<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The SMS credit wallet, and every message that ever left.
 *
 * ## The wallet is a ledger, not a column
 *
 * Golden rule 3, and here it is real money: a shop pre-pays for credit and every message
 * spends some. A stored `credit_balance` that a job increments drifts the first time a
 * worker dies between the gateway call and the update — and it drifts in the direction of
 * the shop having paid for messages it cannot account for, which is the version that ends
 * up in a support ticket.
 *
 * So the balance is `SUM(amount)` over signed entries. A top-up is positive, a charge is
 * negative, a refund is positive again with its own type so the trail shows both the charge
 * and its reversal rather than a netted nothing.
 *
 * Signed amounts rather than the debit/credit pair the main ledger uses: this is a
 * single-sided internal counter, not double-entry bookkeeping a shopkeeper reads. Borrowing
 * the two-column shape would imply a balancing entry that does not exist.
 *
 * ## Messages are rows before they are sent
 *
 * A message is written `queued`, charged, and only then handed to a driver. The row exists
 * before the gateway is called so that a worker dying mid-send leaves evidence — the
 * alternative is a charge with nothing to explain it.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('sms_credit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Signed rial. Positive adds credit, negative spends it.
            $table->bigInteger('amount');

            $table->string('type')->comment('topup | charge | refund | adjustment');
            $table->string('description')->nullable();

            $table->nullableMorphs('reference');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'occurred_at'], 'sms_credit_entries_tenant_occurred_idx');
            $table->index(['tenant_id', 'type'], 'sms_credit_entries_tenant_type_idx');
        });

        DB::statement(
            "alter table sms_credit_entries
             add constraint sms_credit_entries_type
             check (type in ('topup', 'charge', 'refund', 'adjustment'))"
        );

        // A charge that adds credit, or a top-up that removes it, is a sign error — and a
        // sign error in a wallet is money. The database refuses both.
        DB::statement(
            "alter table sms_credit_entries
             add constraint sms_credit_entries_sign
             check (
                 (type = 'charge' and amount < 0)
                 or (type in ('topup', 'refund') and amount > 0)
                 or type = 'adjustment'
             )"
        );

        $this->enableRls('sms_credit_entries');

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();

            // Canonical +98 form. Stored normalised so an opt-out list can match it — see
            // PhoneNumber on why four spellings of one number is the bug that reaches a
            // regulator.
            $table->string('to', 20);

            $table->string('template_key')->nullable()->comment('Which automation or template produced it');
            $table->string('provider_template_id')->nullable();

            // Positional, in template order — the order on the wire.
            $table->jsonb('tokens')->nullable();
            $table->text('body')->nullable();

            $table->string('status')->default('queued')->comment('queued | sent | failed | suppressed');
            $table->string('driver')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('error')->nullable();

            $table->unsignedInteger('segments')->default(1);
            $table->unsignedBigInteger('cost')->default(0)->comment('Rial charged to the wallet');

            /*
            | The idempotency key for anything an automation generated.
            |
            | `installment-due:{row}:1405-06-15`, `birthday:{party}:1405`, and so on — the
            | period-keyed pattern from docs/specs/treasury.md. A scheduler that runs twice
            | must not text a customer twice, and the guarantee has to live in the database
            | because two workers both read "not yet" and both send.
            */
            $table->string('idempotency_key')->nullable();

            $table->nullableMorphs('reference');

            $table->timestampTz('queued_at');
            $table->timestampTz('sent_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'messages_tenant_status_idx');
            $table->index(['tenant_id', 'queued_at'], 'messages_tenant_queued_idx');
            $table->index(['tenant_id', 'party_id'], 'messages_tenant_party_idx');
        });

        DB::statement(
            'create unique index messages_idempotency_once
             on messages (tenant_id, idempotency_key)
             where idempotency_key is not null'
        );

        $this->enableRls('messages');

        Schema::create('message_opt_outs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // The number, not the party. A person who asks to stop hearing from a shop is
            // asking about a phone — and the same number may appear on three party rows
            // after an import, none of which is obviously the one they meant.
            $table->string('phone', 20);

            $table->string('reason')->nullable();
            $table->timestampTz('opted_out_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'phone'], 'message_opt_outs_tenant_phone_unique');
        });

        $this->enableRls('message_opt_outs');
    }

    public function down(): void
    {
        Schema::dropIfExists('message_opt_outs');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('sms_credit_entries');
    }
};
