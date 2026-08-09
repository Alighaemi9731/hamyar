<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-to-shop notices: maintenance windows, new modules, price changes.
 *
 * Central and deliberately NOT tenant-scoped — one row is written once and read by every
 * shop, so there is no `tenant_id` and no RLS policy. Targeting a single shop is done by
 * `tenant_id` being set on the row; null means everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();

            // Null = every shop. Set = one shop, for a targeted notice such as
            // "your payment failed twice".
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('body');

            $table->string('level')->default('info')->comment('info | warning | critical');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'starts_at']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
