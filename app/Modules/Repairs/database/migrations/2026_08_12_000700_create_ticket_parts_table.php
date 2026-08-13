<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parts planned for, and fitted to, a device.
 *
 * The repair-side record. The **hold** on the stock lives in Inventory's
 * `stock_reservations`, because "what may a till sell" is an inventory question that no
 * module should have to know the repair bench is involved in.
 *
 * `unit_cost` is snapshotted at consumption, the same discipline as
 * `sales_invoice_items.cost_snapshot` and for the same reason: repair profit is labour
 * plus parts margin less outsourcing, and a margin recomputed at today's cost restates
 * a job finished three months ago.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('ticket_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('repair_ticket_id');
            $table->foreignId('product_variant_id');
            $table->foreignId('warehouse_id');

            // The hold in Inventory. Null once the row is closed.
            $table->foreignId('stock_reservation_id')->nullable();

            $table->unsignedInteger('quantity');

            // What the shop paid for it — written at consumption, from the weighted
            // average at that moment. Zero while merely reserved: nothing has been
            // consumed, so there is no cost to attribute yet.
            $table->unsignedBigInteger('unit_cost')->default(0);

            // What the customer is charged. Separate from cost, because parts margin is
            // a real part of repair profit and a shop prices a screen above what it paid.
            $table->unsignedBigInteger('unit_price')->default(0);

            $table->string('state', 16)->default('reserved');

            $table->foreignId('actor_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'repair_ticket_id', 'state']);
        });

        $this->enableRls('ticket_parts');

        DB::statement(
            'alter table ticket_parts
             add constraint ticket_parts_quantity_is_positive
             check (quantity > 0)'
        );

        DB::statement(
            "alter table ticket_parts
             add constraint ticket_parts_state_is_known
             check (state in ('reserved', 'consumed', 'returned'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_parts');
    }
};
