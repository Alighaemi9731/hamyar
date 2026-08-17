<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سامانه مودیان — the submission log and the shop's credentials.
 *
 * Ships disabled ([ADR 0011](../../../../docs/adr/0011-moadian-adapter-without-a-provider.md)):
 * no real provider was chosen for launch, `MOADIAN_ENABLED` is false everywhere, and the
 * only driver is a fake. The tables exist because the queue, the inbox and the mapping are
 * the parts that are expensive to retrofit into the invoice path later, under pressure from
 * a shop that has just been told it must file electronically.
 *
 * ## `payload` stores exactly what was sent
 *
 * Not a reconstruction. A rejection arriving three days later has to be answerable with
 * "here is the document the authority received", and re-deriving it from the invoice would
 * answer with what the invoice looks like *today* — after somebody edited the buyer's
 * economic code in response to the very rejection being investigated.
 *
 * ## One live submission per invoice, enforced by a partial index
 *
 * `attempts` counts retries against one row rather than each attempt inserting a new one:
 * the spec's acceptance line is "resend is idempotent — it does not create a duplicate
 * submission", and the guarantee belongs in the database, because two workers both reading
 * "not yet submitted" is exactly the race a queue makes likely.
 *
 * Cancellations are the deliberate exception. A void submits a *second* document referencing
 * the first, so the index is partial on `type = 'main'`.
 *
 * ## The private key is encrypted and never leaves
 *
 * `private_key` is cast `encrypted` on the model and is absent from every resource, response
 * and export. The spec makes it an acceptance criterion; the column comment is here so that
 * anybody writing a `select *` into a debug dump reads it first.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('moadian_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('memory_id')->nullable()->comment('شناسه حافظه مالیاتی');
            $table->string('economic_code')->nullable();
            $table->string('provider')->default('fake')
                ->comment('Only `fake` exists — ADR 0011, no real provider chosen for launch');

            // Encrypted at rest via the model cast. NEVER select this into a log, a
            // response, an export or a debug dump.
            $table->text('private_key')->nullable();

            $table->boolean('is_enabled')->default(false);

            $table->timestamps();

            $table->unique('tenant_id');
        });

        $this->enableRls('moadian_settings');

        Schema::create('moadian_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();

            $table->string('type')->default('main')->comment('main | cancel | correction');

            // Exactly what was put on the wire. See the class docblock.
            $table->jsonb('payload');

            $table->string('status')->default('pending')
                ->comment('pending | sending | accepted | rejected | failed');

            $table->string('reference_number')->nullable()->comment('The authority’s id for the document');
            $table->string('tax_id')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'moadian_invoices_tenant_status_idx');
            $table->index(['tenant_id', 'sales_invoice_id'], 'moadian_invoices_tenant_invoice_idx');
        });

        $this->enableRls('moadian_invoices');

        DB::statement(
            "alter table moadian_invoices
             add constraint moadian_invoices_status
             check (status in ('pending', 'sending', 'accepted', 'rejected', 'failed'))"
        );

        DB::statement(
            "alter table moadian_invoices
             add constraint moadian_invoices_type
             check (type in ('main', 'cancel', 'correction'))"
        );

        // One main submission per invoice. A resend updates this row; it never inserts a
        // second one, which is what makes resend idempotent under two concurrent workers.
        DB::statement(
            "create unique index moadian_invoices_one_main
             on moadian_invoices (tenant_id, sales_invoice_id)
             where type = 'main'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('moadian_invoices');
        Schema::dropIfExists('moadian_settings');
    }
};
