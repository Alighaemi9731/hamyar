<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central catalogue: what we sell.
 *
 * All of these are central tables — no `tenant_id`, no RLS. A plan is a property of
 * the product, not of a shop, and every tenant reads the same catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table): void {
            $table->id();

            // Matches app/Modules/<Name> lowercased — the same code used for
            // `module:<code>` and the route middleware checks.
            $table->string('code')->unique();
            $table->string('name_fa');
            $table->string('description_fa')->nullable();

            // Can a shop buy this on its own, on top of a cheaper plan? Repairs and
            // installments are the ones shops actually ask for individually.
            $table->boolean('is_addonable')->default(false);
            $table->unsignedBigInteger('addon_price')->nullable()
                ->comment('Monthly add-on price in RIAL (golden rule 2)');

            // Modules every plan includes and nobody can turn off.
            $table->boolean('is_core')->default(false);

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_fa');
            $table->string('tagline_fa')->nullable();

            $table->string('interval')->default('month')->comment('month | quarter | year');

            // BIGINT rial. Never a decimal, never a float (golden rule 2).
            $table->unsignedBigInteger('price');

            $table->unsignedSmallInteger('trial_days')->default(14);

            // Off the pricing page but still assignable — for grandfathered or
            // negotiated plans, which every B2B product ends up needing.
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();
        });

        Schema::create('plan_module', function (Blueprint $table): void {
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();

            $table->primary(['plan_id', 'module_id']);
        });

        Schema::create('plan_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            // Null means unlimited. A sentinel like -1 or 0 reads as "none" to anyone
            // skimming the table, which is the opposite of what it means.
            $table->string('key')->comment('users | branches | invoices_per_month | storage_mb | sms_credit_bonus');
            $table->unsignedBigInteger('value')->nullable();

            $table->timestamps();

            $table->unique(['plan_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_limits');
        Schema::dropIfExists('plan_module');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('modules');
    }
};
