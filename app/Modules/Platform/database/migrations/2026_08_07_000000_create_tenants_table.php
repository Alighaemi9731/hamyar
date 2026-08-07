<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central table — deliberately NO `tenant_id` and NO RLS. This is the registry the
 * tenancy system itself is built on; scoping it to a tenant would be circular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            $table->string('status')->default('trialing')
                ->comment('trialing | active | suspended | archived');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();

            // Display preferences and per-shop toggles. Real settings live in the
            // Settings module's key/value table; this holds only what tenancy itself
            // needs before any module boots.
            $table->jsonb('settings')->default('{}');

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
