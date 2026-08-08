<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice numbers are unique **per shop**, not globally.
 *
 * The original index was a plain `unique('number')`, which is wrong the moment a second
 * tenant exists: counters are per-tenant by design, so every shop's first invoice is
 * `SUB-00001` and the second shop to bill collides with the first.
 *
 * Caught by a test that bills two tenants in sequence. It would not have shown up in any
 * single-tenant test, and in production it would have surfaced as a shop unable to buy a
 * plan — with the failure landing on whichever tenant happened to be second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropUnique(['number']);
            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'number']);
            $table->unique(['number']);
        });
    }
};
