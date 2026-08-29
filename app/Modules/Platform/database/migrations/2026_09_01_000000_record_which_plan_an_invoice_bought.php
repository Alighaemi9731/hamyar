<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which plan an invoice was for — the column that makes an upgrade actually upgrade.
 *
 * `subscription_invoices.lines` has always carried the plan's *name*, because an invoice
 * must read the same after the plan is renamed or repriced. That is the right rule for a
 * record and useless for a decision: `BillingService::applyPayment()` had no machine-
 * readable way to know which plan the money bought, so it extended the period and left
 * `subscriptions.plan_id` untouched. A shop upgraded, paid, and stayed on the old plan.
 *
 * So the snapshot keeps saying what it said, and this column says what it means.
 * Nullable: invoices written before this migration have no plan to point at, and the
 * settlement path treats a null as "extend the period, change nothing" — exactly the
 * behaviour those rows already got.
 *
 * `subscriptions.plan_changed_at` is the audit half. Support's first question about a
 * wrong plan is "when did it change", and a column that is only ever written beside
 * `plan_id` can always answer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            // Null on delete, never cascade: an invoice is a financial record and must
            // outlive its plan. (Plans cannot be deleted in the panel at all, so this is
            // the belt to that braces — but a cascade here would delete revenue history
            // to satisfy a foreign key, which is never the right trade.)
            $table->foreignId('plan_id')->nullable()->after('subscription_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('plan_changed_at')->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_id');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('plan_changed_at');
        });
    }
};
