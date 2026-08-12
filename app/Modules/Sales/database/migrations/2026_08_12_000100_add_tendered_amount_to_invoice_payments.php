<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer handed over, as distinct from what it settled.
 *
 * ## Why change is a stored fact and not a screen calculation
 *
 * A customer pays 5,000,000 rial against a 4,820,000 invoice and gets 180,000 back. The
 * drawer ends the day 4,820,000 heavier, so `amount` — the settled figure — is the one
 * the ledger and the Z-report need, and it is what this column does **not** replace.
 *
 * But the receipt says «پرداختی ۵۰۰,۰۰۰ تومان — باقی‌مانده ۱۸,۰۰۰ تومان», and a receipt
 * reprinted next week has to say the same thing. Deriving change from `amount` alone is
 * impossible: the arithmetic ran once, at the counter, against a number nobody kept.
 * Storing it makes the reprint identical to the original, which is the whole point of a
 * reprint.
 *
 * Nullable, because most payment rows have nothing to say here: a card-to-card transfer
 * is for an exact amount and a cheque is written for its face value. Only cash is
 * tendered in round notes. A null means "the same as `amount`", not "unknown".
 *
 * ## The `trade_in` method
 *
 * Recorded here as a comment change only — the column is a string, so the enum can grow
 * without a schema change. It is listed now so the comment does not go stale the moment
 * Phase 5.4 lands, and so anybody reading the table sees every value it can hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('tendered_amount')->nullable()->after('amount');
        });

        // Tendered can equal the settled amount but never fall below it: a customer
        // cannot hand over less than the payment claims to settle, and a row saying they
        // did would put change into the drawer that was never taken out of it.
        DB::statement(
            'alter table invoice_payments
             add constraint invoice_payments_tendered_covers_amount
             check (tendered_amount is null or tendered_amount >= amount)'
        );

        DB::statement(
            "comment on column invoice_payments.method is
             'cash | pos_terminal | card_to_card | cheque | credit | trade_in'"
        );
    }

    public function down(): void
    {
        DB::statement('alter table invoice_payments drop constraint if exists invoice_payments_tendered_covers_amount');

        Schema::table('invoice_payments', function (Blueprint $table): void {
            $table->dropColumn('tendered_amount');
        });
    }
};
