<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop staff. Tenant-scoped, RLS-protected.
 *
 * Replaces Laravel's default users migration, which was removed: it had no
 * `tenant_id`, and adding one afterwards would have left a window in which the table
 * existed without a policy.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('mobile')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->string('password');

            $table->boolean('is_active')->default(true);

            // Encrypted at rest by the model cast; the column is text because
            // ciphertext is far longer than the secret.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            // Unique PER TENANT, not globally: the same person may work at two shops,
            // and two unrelated shops may each have an "info@" address. A global
            // unique index here would leak the existence of other tenants' accounts
            // through registration errors.
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'mobile']);

            $table->index(['tenant_id', 'is_active']);
        });

        $this->enableRls('users');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
