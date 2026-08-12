<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قبض پذیرش — the repair intake receipt, and the trail behind it.
 *
 * ## The passcode column is the reason this file is careful
 *
 * `device_passcode` holds the code that unlocks somebody else's phone. It is stored
 * encrypted by the model cast, never in plaintext, and the column is deliberately
 * `text` rather than a short varchar because ciphertext is much longer than the four
 * digits it protects — sizing it to the secret would have leaked the secret's length.
 *
 * A leaked unlock code is a stolen phone, so the protection is layered and every layer
 * is somewhere different: encrypted at rest here, hidden from serialisation on the
 * model, gated behind `repairs.reveal_passcode` in the policy, and audited on every
 * reveal. Tests assert it appears in no log, no JSON response and no export.
 *
 * ## Why the device is denormalised beside the unit link
 *
 * `product_unit_id` links a repair to a handset this shop sold — that is what makes the
 * IMEI passport answer "repaired when". But most devices coming in for repair were
 * never sold here, so brand, model and IMEI are also stored as plain columns. A ticket
 * that could only exist for our own stock would refuse the majority of a shop's work.
 *
 * ## The tracking token is not the ticket code
 *
 * `code` is short and printable and gets read out over the phone. `tracking_token` is
 * long and random and is what the public URL carries. Letting the public page key on
 * the code would make every shop's tickets enumerable by typing `REP-000001`.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('repair_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('branch_id');

            // Short, printable, per tenant+branch from `counters` — never MAX(+1).
            $table->string('code', 32);

            $table->foreignId('party_id')->nullable();

            // Set only when this shop sold the device. Nullable by necessity: most
            // repairs are for phones bought somewhere else.
            $table->foreignId('product_unit_id')->nullable();

            $table->string('device_brand', 80)->nullable();
            $table->string('device_model', 120);
            $table->string('device_imei', 20)->nullable();
            $table->string('device_colour', 40)->nullable();

            $table->text('reported_issue');

            $table->string('status', 32)->default('queued');
            $table->foreignId('technician_id')->nullable();
            $table->unsignedTinyInteger('priority')->default(2);
            $table->timestamp('promised_at')->nullable();

            // Money, integer rial (golden rule 2).
            $table->unsignedBigInteger('estimate_amount')->default(0);
            $table->unsignedBigInteger('approved_amount')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_via', 16)->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->text('approval_note')->nullable();
            $table->unsignedBigInteger('prepaid_amount')->default(0);

            $table->unsignedSmallInteger('warranty_days')->default(0);

            /*
            | The customer's unlock code. `text`, because what lands here is ciphertext
            | from the model's `encrypted` cast and is far longer than the secret. Sizing
            | this column to a plausible passcode length would have been a hint about the
            | thing it is protecting.
            */
            $table->text('device_passcode')->nullable();

            // What came in with the phone: case, SIM tray, charger, memory card. A list
            // rather than columns — every shop's differs, and it is only ever displayed
            // and compared at handover.
            $table->json('accessories')->nullable();

            // Long and random; the public tracking URL carries this, never `code`.
            $table->string('tracking_token', 64);

            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();

            $table->foreignId('intake_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Composite, leading with tenant_id (golden rule 1).
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'tracking_token']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'technician_id', 'status']);
            $table->index(['tenant_id', 'party_id']);
            // The lookup behind "has this device been in before?" — asked with the phone
            // in hand, at the counter.
            $table->index(['tenant_id', 'device_imei']);
        });

        $this->enableRls('repair_tickets');

        DB::statement(
            "comment on column repair_tickets.device_passcode is
             'ENCRYPTED. Never select into logs, JSON or exports. Reveal requires repairs.reveal_passcode and is audited.'"
        );

        DB::statement(
            'alter table repair_tickets
             add constraint repair_tickets_priority_is_known
             check (priority between 1 and 3)'
        );

        /*
        | Append-only. The passport of a repair, and the answer to "who moved this and
        | when" — which is the question asked when a device has sat for three weeks.
        */
        Schema::create('ticket_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('repair_ticket_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'repair_ticket_id', 'id']);
        });

        $this->enableRls('ticket_status_histories');

        /*
        | What the device looked like at drop-off. This is what settles "the screen was
        | already cracked" three weeks later, so the answers are stored per ticket rather
        | than summarised into a note somebody paraphrased.
        */
        Schema::create('ticket_checklist_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('repair_ticket_id');
            $table->string('item_key', 80);
            $table->string('label', 160);
            $table->string('answer', 40);
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'repair_ticket_id', 'item_key']);
        });

        $this->enableRls('ticket_checklist_answers');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_checklist_answers');
        Schema::dropIfExists('ticket_status_histories');
        Schema::dropIfExists('repair_tickets');
    }
};
