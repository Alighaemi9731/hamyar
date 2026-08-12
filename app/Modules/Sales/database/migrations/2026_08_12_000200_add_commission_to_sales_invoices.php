<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the salesperson earned on this sale.
 *
 * Approved at DECISION GATE 3: a percentage of the invoice's **margin**, so a discount
 * costs the person who gave it. See {@see App\Support\Settings\CommissionSettings}.
 *
 * ## Why the amount is stored rather than derived on demand
 *
 * It is the same argument as `cost_snapshot`, one column over. The accrual is a fact
 * about the day the sale was made: the rate in force, applied to the margin as it stood.
 * Recomputing it later would restate every past month the moment a shop changes its
 * rate — and payroll has already been run against the old figure.
 *
 * This is not a stored *balance* (golden rule 3). "What do we owe Reza this month" is
 * still a SUM over these rows, never a column somebody updates.
 *
 * ## The rate rides along with it
 *
 * Without it the amount cannot be explained, and "why is my commission 1,125,000?" is a
 * question asked by a person standing in front of you.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('commission_amount')->default(0)->after('paid_total');
            $table->unsignedSmallInteger('commission_rate')->default(0)->after('commission_amount');
        });

        DB::statement(
            'alter table sales_invoices
             add constraint sales_invoices_commission_rate_is_a_percent
             check (commission_rate >= 0 and commission_rate <= 100)'
        );
    }

    public function down(): void
    {
        DB::statement('alter table sales_invoices drop constraint if exists sales_invoices_commission_rate_is_a_percent');

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropColumn(['commission_amount', 'commission_rate']);
        });
    }
};
