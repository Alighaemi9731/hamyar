<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The quantity ledger (golden rule 3).
 *
 * **Quantity on hand is a SUM over this table, never a stored column.** A stored total
 * drifts — a crashed request, a forgotten decrement, a concurrent write — and once it
 * has drifted there is nothing to reconcile it against. A ledger cannot drift: it can
 * only be incomplete, and an incomplete ledger is visible as a movement nobody wrote
 * rather than a number nobody can explain.
 *
 * The cost of that choice is a `SUM` on the read path, which the covering index below
 * turns into an index-only scan. If it ever stops being fast enough the answer is a
 * materialised view refreshed from this table — still derived, never authoritative.
 *
 * Serialized goods do not live here. A phone's location is `product_units.warehouse_id`
 * and its life is `product_unit_histories`; counting phones by summing quantities would
 * throw away exactly the per-unit detail that table exists for.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            // Signed: in is positive, out is negative. One column rather than
            // quantity + direction, because two columns can disagree and one cannot.
            $table->integer('quantity');

            $table->string('type');

            // The document that caused it. Polymorphic because a movement can come from
            // a purchase, a sale, a repair or a count, and none of those modules should
            // have to know about the others.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // Cost at the moment of the movement, in rial. Snapshotted so a stock
            // valuation for a past date does not need today's prices.
            $table->unsignedBigInteger('unit_cost')->default(0);

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamp('occurred_at')->useCurrent();

            // No `updated_at`: a ledger entry is never edited. A mistake is corrected by
            // writing an opposing movement, which leaves both the error and the fix
            // visible — that is the whole point of a ledger.
            $table->timestamp('created_at')->useCurrent();

            // The covering index for the on-hand SUM.
            $table->index(['tenant_id', 'product_variant_id', 'warehouse_id'], 'stock_movements_on_hand');
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        $this->enableRls('stock_movements');

        // A zero-quantity movement is always a bug — a caller computing a delta that
        // turned out to be nothing — and it pollutes the ledger with lines that explain
        // no change at all.
        DB::statement('alter table stock_movements add constraint stock_movements_quantity_not_zero check (quantity <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
