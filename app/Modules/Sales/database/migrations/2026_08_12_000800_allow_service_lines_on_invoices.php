<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice line that sells work rather than goods.
 *
 * ## The original constraint assumed every sale is of stock, and Repairs proves otherwise
 *
 * `sales_invoice_items_one_subject` required a variant or a unit on every line — a
 * reasonable rule while the only thing the product sold was things on a shelf. A repair
 * bill is «تعویض صفحه ۱٬۲۰۰٬۰۰۰»: real money for real work, attached to no catalogue row.
 *
 * The alternative was a fake catalogue product per service, which is worse in three
 * concrete ways: it puts labour into stock valuation, it makes the POS scanner offer
 * "screen replacement" as something to sell, and it means every shop has to maintain
 * catalogue rows for work they price per job anyway.
 *
 * ## Why `is_service` rather than "both ids are null"
 *
 * Both-null already implies it, and inferring intent from an absence is how a genuine
 * bug — a line that lost its variant id — becomes indistinguishable from a deliberate
 * service charge. The flag says which one somebody meant.
 *
 * ## What a service line does NOT do
 *
 * It writes no stock movement and carries no cost snapshot from the ledger.
 * `FinaliseInvoice` already returns early for a line with no variant, so both fall out
 * for free — this migration only widens what the database will accept.
 *
 * Repair parts are billed as service lines too, deliberately. The goods already left the
 * shelf as `repair_consume` when they were fitted; a `sale` movement here would deduct
 * the same screen twice. Their cost is recorded on `ticket_parts.unit_cost`, which is
 * where repair profit is computed from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_items', function (Blueprint $table): void {
            $table->boolean('is_service')->default(false)->after('product_unit_id');
        });

        DB::statement('alter table sales_invoice_items drop constraint if exists sales_invoice_items_one_subject');

        DB::statement(
            'alter table sales_invoice_items add constraint sales_invoice_items_one_subject
             check (num_nonnulls(product_variant_id, product_unit_id) >= 1 or is_service)'
        );

        // A service line has no stock subject. Stating it in the schema stops a caller
        // setting the flag on a line that also deducts a variant, which would bill work
        // and take goods off the shelf under one description.
        DB::statement(
            'alter table sales_invoice_items add constraint sales_invoice_items_service_has_no_subject
             check (not is_service or num_nonnulls(product_variant_id, product_unit_id) = 0)'
        );
    }

    public function down(): void
    {
        DB::statement('alter table sales_invoice_items drop constraint if exists sales_invoice_items_service_has_no_subject');
        DB::statement('alter table sales_invoice_items drop constraint if exists sales_invoice_items_one_subject');

        DB::statement(
            'alter table sales_invoice_items add constraint sales_invoice_items_one_subject
             check (num_nonnulls(product_variant_id, product_unit_id) >= 1)'
        );

        Schema::table('sales_invoice_items', function (Blueprint $table): void {
            $table->dropColumn('is_service');
        });
    }
};
