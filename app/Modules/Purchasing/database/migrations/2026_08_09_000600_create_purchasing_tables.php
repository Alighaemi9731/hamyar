<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Getting stock in, and knowing what it truly cost.
 *
 * A purchase invoice is a **draft until it is received**. Nothing touches stock, no
 * `product_units` exist and no ledger entry is written while it is a draft — so a
 * half-typed shipment is not yet a lie about what is on the shelf. `received` is the
 * moment everything happens, in one transaction.
 *
 * Serialized and standard lines are separate tables rather than one with a nullable
 * IMEI. A standard line has a quantity; a serialized line has N handsets each with its
 * own IMEI and its own cost. Forcing them into one shape means every consumer has to ask
 * which kind it is before it can read the row.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            // The supplier. Nullable: a shop recording opening stock it already owned
            // has nobody to have bought it from.
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number');

            $table->string('status')->default('draft')->comment('draft | received | void');

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('received_at')->nullable();

            // All integer rial (golden rule 2). Stored rather than derived because an
            // invoice must keep saying what it said, even after a price or a VAT rate
            // changes underneath it.
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('vat_amount')->default(0);
            $table->unsignedBigInteger('landed_total')->default(0);
            $table->unsignedBigInteger('total')->default(0);

            $table->text('notes')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'party_id']);
            $table->index(['tenant_id', 'received_at']);
        });

        $this->enableRls('purchase_invoices');

        // Per branch, like every other document number (docs/specs/settings.md).
        DB::statement(
            'create unique index purchase_invoices_number_unique
             on purchase_invoices (tenant_id, number)
             where deleted_at is null'
        );

        Schema::create('purchase_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cost')->comment('Before landed costs');
            $table->unsignedBigInteger('line_total');

            // Filled when the invoice is received. Kept separate from `unit_cost` so the
            // screen can show what the supplier charged next to what it really cost.
            $table->unsignedBigInteger('landed_allocation')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'purchase_invoice_id']);
        });

        $this->enableRls('purchase_invoice_items');

        Schema::create('purchase_unit_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            // Not yet a product_unit — that is created on receipt. Until then this is
            // just what the operator typed.
            $table->string('imei1', 15)->nullable();
            $table->string('imei2', 15)->nullable();
            $table->string('serial')->nullable();

            $table->string('condition')->default('new');
            $table->string('grade', 2)->nullable();

            $table->unsignedBigInteger('unit_cost');
            $table->unsignedBigInteger('landed_allocation')->default(0);

            // Set on receipt, linking the intake row to the device it became. This is
            // the first link in the IMEI passport.
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'purchase_invoice_id']);
            $table->index(['tenant_id', 'imei1']);
        });

        $this->enableRls('purchase_unit_items');

        Schema::create('landed_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();

            $table->string('type')->default('freight')->comment('freight | customs | courier | other');
            $table->unsignedBigInteger('amount');
            $table->string('allocation')->default('by_value')->comment('by_value | by_quantity');

            $table->string('description')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'purchase_invoice_id']);
        });

        $this->enableRls('landed_costs');

        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number');
            $table->unsignedBigInteger('total')->default(0);
            $table->text('reason')->nullable();

            $table->timestamp('returned_at')->useCurrent();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'purchase_invoice_id']);
        });

        $this->enableRls('purchase_returns');

        Schema::create('purchase_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            // Set for a serialized return: which physical handset went back.
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_cost');

            $table->timestamps();

            $table->index(['tenant_id', 'purchase_return_id']);
        });

        $this->enableRls('purchase_return_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('landed_costs');
        Schema::dropIfExists('purchase_unit_items');
        Schema::dropIfExists('purchase_invoice_items');
        Schema::dropIfExists('purchase_invoices');
    }
};
