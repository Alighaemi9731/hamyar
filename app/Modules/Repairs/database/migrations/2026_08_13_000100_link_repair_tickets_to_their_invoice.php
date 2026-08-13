<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which invoice settled this repair.
 *
 * ## The question had no answer
 *
 * Delivery has built a `SalesInvoice` since the delivery commit, and nothing recorded
 * which one. The only trace was the invoice's `notes` string — "بابت تعمیر REP-000001" —
 * and answering "has this repair been paid for?" by parsing a Persian sentence is not an
 * answer, it is a bug waiting for somebody to rename the note.
 *
 * ## It was telling customers they owed money they had already paid
 *
 * That gap surfaced on the public tracking page, which is the worst place for it. The
 * page computed `amount_due` as approved-minus-prepaid and kept showing it after
 * delivery, because the ticket genuinely did not know an invoice existed. A customer who
 * had settled in full and walked out with their phone could open the link that evening
 * and read that they still owed 1,100,000 toman.
 *
 * Found by walking the Phase 6 DoD end to end. Every test passed: they asserted on the
 * ticket and on the invoice, and nothing asked what the customer sees afterwards.
 *
 * ## Nullable, and `nullOnDelete`
 *
 * Most tickets have no invoice — everything before delivery, plus rejected and abandoned
 * devices that are handed back unbilled. And a voided invoice that is later deleted must
 * not take the repair history with it: the device was still repaired, the parts were
 * still consumed, and the ticket is still the record of what happened to somebody's
 * phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_tickets', function (Blueprint $table): void {
            $table->foreignId('sales_invoice_id')
                ->nullable()
                ->after('party_id')
                ->constrained('sales_invoices')
                ->nullOnDelete();

            // Leading with tenant_id, per golden rule 1 — the shape every tenant-scoped
            // lookup takes, and the one an index that led with the invoice would miss.
            $table->index(['tenant_id', 'sales_invoice_id'], 'repair_tickets_tenant_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::table('repair_tickets', function (Blueprint $table): void {
            $table->dropIndex('repair_tickets_tenant_invoice_idx');
            $table->dropConstrainedForeignId('sales_invoice_id');
        });
    }
};
