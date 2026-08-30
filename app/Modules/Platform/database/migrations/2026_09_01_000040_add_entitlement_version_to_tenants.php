<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One integer that tells every process its idea of a shop's limits is out of date.
 *
 * `LimitResolver` memoises limits per tenant per process, because resolving them on every
 * `consume()` would put two queries on the hot path of a POS screen. A memo that nothing
 * invalidates is how a shop upgrades and a Horizon worker keeps refusing its work for as
 * long as that worker lives — the ADR 0012 failure mode, one level up.
 *
 * A durable counter is the smallest thing that fixes it. Bumped when a subscription is
 * activated, when a scheduled downgrade lands, when an override is saved, and when a
 * plan's limits are edited in the panel; every process compares its memo's version with
 * the current one and re-resolves on a mismatch. It survives a Redis flush, which a
 * cache-only signal does not, and it is one column rather than a second cache to reason
 * about.
 *
 * On `tenants` rather than `subscriptions` because it must have an answer for a shop with
 * no subscription row at all, and because an override is a property of the shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedInteger('entitlement_version')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('entitlement_version');
        });
    }
};
