<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central table. Maps an incoming hostname to a tenant.
 *
 * Separate from `tenants.slug` on purpose: a shop keeps its original subdomain
 * forever (links, QR codes on printed receipts and repair tracking pages outlive any
 * rename), and may later add a custom domain. One tenant, many hostnames, exactly one
 * primary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // The full hostname as it arrives on the request: "demo.app.localhost" or
            // a future custom domain. Globally unique — this is the lookup key, and an
            // ambiguous hostname would mean serving one shop's data on another's URL.
            $table->string('hostname')->unique();

            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
