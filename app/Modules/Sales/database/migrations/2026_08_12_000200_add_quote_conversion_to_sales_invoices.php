<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which invoice a پیش‌فاکتور turned into.
 *
 * ## Why conversion is a second row and not a status change
 *
 * A quote is a document the shop handed to a customer, with a number on it — `QUO-000014`
 * — that the customer quotes back over the phone a week later. Flipping that row's `type`
 * to `invoice` and re-numbering it `INV-000231` destroys the only record of the document
 * the customer is holding.
 *
 * So conversion copies the lines into a new draft invoice and leaves the quote standing,
 * with this column pointing at what it became. Both numbers survive, «این پیش‌فاکتور
 * تبدیل شد به فاکتور …» is answerable, and so is the reverse.
 *
 * The same reasoning as a return being its own credit document rather than an edit of the
 * sale: a document that was given to somebody is a fact, and facts are appended to, never
 * rewritten.
 *
 * Nullable and self-referencing. `nullOnDelete` rather than cascade: if the invoice is
 * ever hard-deleted, the quote should survive as an unconverted quote — losing it too
 * would delete a document nobody asked to delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->foreignId('converted_to_id')
                ->nullable()
                ->after('type')
                ->constrained('sales_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('converted_to_id');
        });
    }
};
