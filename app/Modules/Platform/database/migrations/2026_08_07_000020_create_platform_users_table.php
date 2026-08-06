<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central table: MobiShop staff, not shop staff.
 *
 * A separate table and a separate guard from tenant `users`, rather than a flag on
 * one shared table. Three reasons, in order of importance:
 *
 * 1. `users` is tenant-scoped and RLS-protected. A platform admin belongs to no
 *    tenant, so on the shared table they would either be invisible to themselves or
 *    would need an RLS exemption — and an exemption on the users table is precisely
 *    the hole an attacker wants.
 * 2. A compromised shop account can never escalate into the platform panel, because
 *    the two authenticate against different tables through different guards.
 * 3. Impersonation stays explicit: the admin keeps their own session and assumes a
 *    tenant identity, which is what makes the audit trail meaningful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_users', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            $table->boolean('is_active')->default(true);

            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->timestamp('last_login_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_users');
    }
};
