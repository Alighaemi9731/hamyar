<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving stock between locations, and checking what is actually there.
 *
 * ## Transfers are two-step on purpose
 *
 * Stock leaves the source on **dispatch** and arrives at the destination on **receipt**.
 * Between those two moments it belongs to neither, and that is the point: goods in
 * transit are visible as in transit, and cannot be sold at either end. A one-step
 * transfer makes a van full of phones sellable in two shops at once.
 *
 * ## Counts are sessions, not edits
 *
 * A stock count records what was *counted*, and the difference becomes an adjustment
 * movement. It never sets a total. "We were three short in Mordad" stays answerable,
 * and a shrinkage figure exists to be reported on.
 *
 * `is_blind` hides the expected quantity from the counter. A number on the screen is a
 * number people count towards — blind counting is the only kind that finds anything.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('from_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->cascadeOnDelete();

            $table->string('number');
            $table->string('status')->default('draft')->comment('draft | dispatched | received | canceled');

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'number']);
        });

        $this->enableRls('stock_transfers');

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            // Set for a serialized transfer: which physical handset is in the van.
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('quantity')->default(1);

            // What the receiving end actually found. Null until receipt; a shortfall is
            // a real event that needs recording rather than silently accepting.
            $table->unsignedInteger('received_quantity')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'stock_transfer_id']);
        });

        $this->enableRls('stock_transfer_items');

        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            $table->string('number');
            $table->string('status')->default('open')->comment('open | applied | canceled');

            // Hides the expected figure from whoever is counting. A number on the screen
            // is a number people count towards.
            $table->boolean('is_blind')->default(true);

            $table->timestamp('applied_at')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'number']);
        });

        $this->enableRls('stock_counts');

        Schema::create('stock_count_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            // Snapshotted when the line is added, so the variance is measured against
            // what the system believed at count time — not against a figure that moved
            // while the counting was happening.
            $table->integer('expected_quantity');
            $table->integer('counted_quantity')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'stock_count_id']);
            $table->unique(['stock_count_id', 'product_variant_id']);
        });

        $this->enableRls('stock_count_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
