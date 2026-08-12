<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock that is still on the shelf but already spoken for.
 *
 * ## Why a reservation is not a stock movement
 *
 * A screen protector set aside for tomorrow's repair has not left the building. Writing
 * a movement for it would make the shelf count disagree with the shelf, which is the one
 * thing the stock ledger exists to prevent (golden rule 3) — and it would have to be
 * reversed if the job is cancelled, leaving two movements describing something that
 * never physically happened.
 *
 * So `stock_movements` stays a record of goods actually moving, and this table records
 * intent. On-hand is unchanged by a reservation; **available** is on-hand minus the
 * active ones, and that is the figure a till must sell against.
 *
 * ## Why it lives in Inventory rather than in Repairs
 *
 * Repairs is the first module to need it and will not be the last — a parked sale, a
 * dispatched-but-unreceived transfer and a held online order are the same shape. Putting
 * it here means one definition of "available", one place the POS reads, and no module
 * having to know which other modules might be holding stock.
 *
 * The holder is polymorphic for the same reason. Repairs writes `RepairTicket` rows;
 * nothing in this table imports Repairs.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('product_variant_id');
            $table->foreignId('warehouse_id');

            // Who is holding it. `RepairTicket` today.
            $table->string('holder_type');
            $table->unsignedBigInteger('holder_id');

            $table->unsignedInteger('quantity');

            /*
            | `active`   — on the shelf, spoken for, invisible to a till
            | `consumed` — fitted to the device; a stock movement now exists
            | `released` — the job was cancelled; nothing ever moved
            |
            | Closed rows are kept rather than deleted: "why did the shop think it had
            | two of these last Tuesday" is a question somebody asks.
            */
            $table->string('state', 16)->default('active');

            $table->foreignId('actor_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // The hot query: available stock for one variant in one warehouse. Leading
            // with tenant_id (golden rule 1), and `state` in the key so the till's
            // lookup never reads a closed row.
            $table->index(
                ['tenant_id', 'product_variant_id', 'warehouse_id', 'state'],
                'stock_reservations_available_index',
            );

            $table->index(['tenant_id', 'holder_type', 'holder_id'], 'stock_reservations_holder_index');
        });

        $this->enableRls('stock_reservations');

        DB::statement(
            'alter table stock_reservations
             add constraint stock_reservations_quantity_is_positive
             check (quantity > 0)'
        );

        DB::statement(
            "alter table stock_reservations
             add constraint stock_reservations_state_is_known
             check (state in ('active', 'consumed', 'released'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
