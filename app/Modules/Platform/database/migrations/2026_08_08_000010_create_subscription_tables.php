<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each shop has actually bought.
 *
 * Platform-owned but tenant-scoped: these carry `tenant_id` and are RLS-protected like
 * any other tenant table, so one shop can never see another's billing. They differ in
 * one respect — the policy also honours the `app.platform` flag, which only
 * `TenantContext::runAsPlatform()` sets, so the Platform module can report MRR and
 * churn across every shop without an exemption that would apply everywhere.
 *
 * They deliberately do NOT use `BelongsToTenant`: the Eloquent scope would fight those
 * cross-tenant platform reads. RLS remains mandatory and enforced.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

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

        // RLS with the platform escape hatch: a tenant can never read or write another
        // shop's subscription, and the Platform module reads across all of them only
        // inside TenantContext::runAsPlatform().
        $this->enableRls('subscriptions', allowPlatform: true);

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

        $this->enableRls('subscription_invoices', allowPlatform: true);

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
