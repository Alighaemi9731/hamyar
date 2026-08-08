<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sequential per-tenant numbers for invoices, repair tickets and anything else a shop
 * expects to run 1, 2, 3 with no gaps.
 *
 * `MAX(number) + 1` is the obvious implementation and it is wrong: two concurrent sales
 * read the same maximum and both write the same invoice number. On a POS with two
 * terminals that is not a rare race, it is Thursday afternoon. Postgres sequences do not
 * help either — they are global, they gap on rollback, and a shop's invoice numbers must
 * be per-tenant and contiguous for tax purposes.
 *
 * So: one row per (tenant, key), incremented under `SELECT … FOR UPDATE`. The row lock
 * serialises exactly the shops that are competing for the same counter and nobody else.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('key')->comment('sales_invoice | repair_ticket | subscription_invoice | …');

            // The last number issued. The next call returns value + 1.
            $table->unsignedBigInteger('value')->default(0);

            // Optional per-period reset, e.g. invoice numbers restarting each Jalali
            // year. Null means the counter never resets.
            $table->string('period')->nullable()->comment('e.g. 1405 — scopes the counter');

            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'period']);
            $table->index(['tenant_id', 'key']);
        });

        $this->enableRls('counters');
    }

    public function down(): void
    {
        Schema::dropIfExists('counters');
    }
};
