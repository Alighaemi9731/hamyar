<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which catalogue line the traded-in device actually is.
 *
 * ## Why the free-text name was not enough
 *
 * `device_name` records what the counter typed — «آیفون ۱۳ سفید ۱۲۸». That is the right
 * thing to keep, because it carries detail the catalogue does not, and it is what the
 * customer and the salesperson agreed a price for.
 *
 * It is the wrong thing to build a `product_unit` from. A unit points at a
 * `product_variant`, and inventing one from a typed string would fill the catalogue with
 * fourteen spellings of the same phone inside a month — none of which any report can add
 * up. So the intake asks which existing serialized line this is, and the typed name rides
 * along beside it.
 *
 * Nullable because the column lands on a table that already has rows in no installation
 * yet, and because a trade-in row is written before the unit is made. The intake refuses
 * to create a unit without it (see `TradeInIntake`), which is where the requirement
 * actually bites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_ins', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('device_name')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trade_ins', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_variant_id');
        });
    }
};
