<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The counter.
 *
 * ## A draft is not a document
 *
 * `draft → final → void`. Only finalisation allocates a number, touches stock, or
 * writes a ledger entry — a draft is a basket someone is still filling. Void reverses
 * every effect and **keeps the number**: a tax invoice number that vanishes is a gap
 * the taxman asks about, so the row survives with `status = void`.
 *
 * ## `cost_snapshot` is the whole profit engine
 *
 * Written at finalisation, never recomputed. Under Iranian inflation, what a handset
 * cost in Ordibehesht and what the same model costs today differ enormously, and a
 * profit report for last month that quotes today's cost is not a report, it is a
 * guess. Serialized lines snapshot the unit's own cost (specific identity); standard
 * lines snapshot the weighted average at the moment of sale.
 *
 * ## Exactly one of variant or unit, enforced by the database
 *
 * A line either sells a quantity of something or one specific handset. Allowing both,
 * or neither, is how a line ends up deducting stock twice or not at all — so it is a
 * CHECK constraint, not a convention.
 *
 * ## Payments are rows, not a column
 *
 * A sale is routinely part cash, part card, part cheque. `paid_total` is a stored
 * convenience for list screens; the truth is `SUM(invoice_payments.amount)`, and the
 * finalisation asserts the two agree before it commits.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            // Nullable: a walk-in paying cash has no account, and forcing one is how
            // a counter ends up with fifty customers called «مشتری».
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();

            // Null until finalisation: a draft has no number to burn.
            $table->string('number')->nullable();

            $table->string('type')->default('invoice')->comment('invoice | quote');
            $table->string('status')->default('draft')->comment('draft | final | void');

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();

            // All integer rial (golden rule 2).
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('vat_amount')->default(0);
            $table->unsignedBigInteger('shipping_amount')->default(0);

            // What rounding the grand total moved it by. Signed, and stored rather
            // than absorbed: an invoice whose lines sum to one number and whose total
            // is another, with nothing explaining the gap, is one a customer argues
            // with and a shop cannot reconcile.
            $table->bigInteger('rounding_adjustment')->default(0);

            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('paid_total')->default(0);

            // What the shop's settings said when this was issued — VAT rate, footer
            // terms, print header. An invoice must keep printing the way it printed,
            // even after the shop changes its settings next month.
            $table->json('settings_snapshot')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'branch_id', 'issued_at']);
            $table->index(['tenant_id', 'party_id']);
        });

        $this->enableRls('sales_invoices');

        // Per tenant AND branch, among live rows. A two-branch shop issues two
        // contiguous sequences, which is what the tax authority expects to see.
        // Partial on `number is not null` so unnumbered drafts do not collide.
        DB::statement(
            'create unique index sales_invoices_number_unique
             on sales_invoices (tenant_id, branch_id, number)
             where number is not null and deleted_at is null'
        );

        Schema::create('sales_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            // Set for a handset: this line sells THAT device, not one like it.
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            // Copied, not looked up: a product renamed next year must not rewrite what
            // this invoice says it sold.
            $table->string('description');

            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedSmallInteger('vat_rate')->default(0)->comment('percent, 0–100');
            $table->unsignedBigInteger('vat_amount')->default(0);
            $table->unsignedBigInteger('line_total');

            // Written at finalisation and never recomputed — see the class docblock.
            $table->unsignedBigInteger('cost_snapshot')->default(0);

            $table->unsignedSmallInteger('warranty_months')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'sales_invoice_id']);
            $table->index(['tenant_id', 'product_variant_id']);
        });

        $this->enableRls('sales_invoice_items');

        // A line sells a quantity or a specific device — never both, never neither.
        DB::statement(
            'alter table sales_invoice_items add constraint sales_invoice_items_one_subject
             check (num_nonnulls(product_variant_id, product_unit_id) >= 1)'
        );

        // NO unique index on `product_unit_id`, deliberately — and the reasoning is
        // worth keeping, because it looks like an obvious safeguard.
        //
        // A handset sold, returned, and sold again to somebody else legitimately
        // appears on two invoices. A unique index cannot tell that case apart from a
        // double-sale, because "which of these invoices is still live" lives in another
        // table and a partial index cannot reach it.
        //
        // So the guard is where it can see the state that matters: a `SELECT … FOR
        // UPDATE` on the unit inside the finalisation transaction, backed by
        // `UnitStateMachine` refusing `sold → sold`. See FinaliseInvoice.

        Schema::create('invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();

            $table->string('method')->comment('cash | pos_terminal | card_to_card | cheque | credit');

            // Which cash box or terminal the money landed in. Null for `credit`, which
            // is not money arriving — it is money not arriving yet.
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('amount');

            // Terminal trace number, card-to-card reference, cheque serial.
            $table->string('reference')->nullable();

            // Cheques are Phase 7. The column exists now so a payment row never has to
            // be rewritten later; the FK lands with the table.
            $table->unsignedBigInteger('cheque_id')->nullable();

            $table->timestamp('received_at')->useCurrent();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'sales_invoice_id']);
            $table->index(['tenant_id', 'method']);
        });

        $this->enableRls('invoice_payments');

        DB::statement('alter table invoice_payments add constraint invoice_payments_amount_positive check (amount > 0)');

        Schema::create('sales_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number');
            $table->unsignedBigInteger('total')->default(0);
            $table->text('reason')->nullable();

            $table->timestamp('returned_at')->useCurrent();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'sales_invoice_id']);
            $table->unique(['tenant_id', 'number']);
        });

        $this->enableRls('sales_returns');

        Schema::create('sales_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_item_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('refund_amount');

            // A returned handset needs looking at before it is sellable again, and the
            // grade it comes back with is rarely the grade it left with.
            $table->string('regrade')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'sales_return_id']);
        });

        $this->enableRls('sales_return_items');

        Schema::create('trade_ins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();

            $table->string('device_name');
            $table->string('imei1')->nullable();
            $table->string('condition')->default('used');
            $table->string('grade')->nullable();

            $table->unsignedBigInteger('agreed_price');

            // The used unit this created. Null only between the row being written and
            // the unit being made, which happens in the same transaction.
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('id_scan_media_id')->nullable();

            // The salesperson confirming they walked the customer through the HAMTA
            // ownership transfer. A boolean someone had to tick, on purpose: the shop
            // carries the liability when a stolen handset is traded in.
            $table->boolean('hamta_ack')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'sales_invoice_id']);
        });

        $this->enableRls('trade_ins');
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_ins');
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('sales_invoice_items');
        Schema::dropIfExists('sales_invoices');
    }
};
