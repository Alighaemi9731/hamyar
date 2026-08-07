<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each shop has actually bought.
 *
 * Central tables (they carry `tenant_id` but belong to the Platform module, which owns
 * the billing relationship). No RLS: a tenant must never read or write its own
 * subscription — that is the platform's record of what it is owed. Tenant-facing
 * screens read it through the Platform module's services, not by querying directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();

            $table->string('status')->default('trialing')
                ->comment('trialing | active | past_due | canceled');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();

            // Set when the shop cancels; they keep access until period_end. Cutting
            // access the moment someone clicks cancel is how you get a chargeback.
            $table->timestamp('canceled_at')->nullable();

            // How long past `current_period_end` a `past_due` shop keeps working.
            $table->timestamp('grace_ends_at')->nullable();

            // Credit carried from a downgrade, in rial. Applied to the next invoice
            // rather than refunded (ADR 0006).
            $table->unsignedBigInteger('credit_balance')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('current_period_end');
        });

        Schema::create('subscription_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained();

            $table->unsignedBigInteger('price')->comment('Rial, snapshotted at purchase');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            $table->unique(['subscription_id', 'module_id']);
        });

        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();

            $table->string('type')->comment('percent | fixed');
            $table->unsignedBigInteger('value')->comment('Percent (1-100) or rial');

            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions')->default(0);
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });

        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number')->unique();

            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('credit_applied')->default(0);
            $table->unsignedBigInteger('total');

            $table->string('status')->default('pending')
                ->comment('pending | paid | failed | canceled');

            $table->timestamp('paid_at')->nullable();
            $table->jsonb('lines')->default('[]');

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_invoice_id')->constrained()->cascadeOnDelete();

            $table->string('gateway')->default('zarinpal');

            // The gateway's own handle for this attempt. UNIQUE, and that uniqueness is
            // the idempotency guarantee: a replayed callback cannot create a second
            // successful attempt for the same authority.
            $table->string('authority')->nullable()->unique();
            $table->string('reference')->nullable();

            $table->unsignedBigInteger('amount');
            $table->string('status')->default('initiated')
                ->comment('initiated | verified | failed | canceled');

            $table->text('error')->nullable();
            $table->jsonb('payload')->default('{}');

            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('subscription_addons');
        Schema::dropIfExists('subscriptions');
    }
};
