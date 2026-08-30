<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove what a plan used to be.
 *
 * `plan_module` decided which modules a plan opened; `subscription_addons` sold the rest
 * one at a time; `modules.is_addonable` and `addon_price` were how we priced them. Nothing
 * has read any of it since `0.15.0`, when every module opened to every shop and a plan
 * started selling quantity instead (ADR 0018, DECISION GATE 6).
 *
 * ## Why this is a separate release rather than part of that one
 *
 * `docs/VERSIONING.md`, the blue/green rule. A deploy runs both containers against one
 * already-migrated database for the length of the cutover, so a column dropped in the same
 * release that stops writing it takes the site down for those seconds — and the 500s come
 * from the OLD container, which reads as a reason to roll back rather than as this
 * release's fault. Additive first, destructive one release later. This is the later one.
 *
 * ## What survives, and why
 *
 * `modules` itself stays: `is_enabled` is the platform kill-switch `EnsureModuleEnabled`
 * consults, and ADR 0011 needs it for Moadian. `is_core` stays as documentation of which
 * modules a shop cannot function without — nothing gates on it, and a column that records
 * a fact is cheaper than the conversation about re-deriving it.
 *
 * Down() rebuilds the shape but not the data. That is the honest reverse: the rows said
 * which plan included which module, and after a release in which nothing wrote them, there
 * is no truthful version of that to restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('subscription_addons');
        Schema::dropIfExists('plan_module');

        Schema::table('modules', function (Blueprint $table): void {
            $table->dropColumn(['is_addonable', 'addon_price']);
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->boolean('is_addonable')->default(false)->after('is_core');
            $table->unsignedBigInteger('addon_price')->nullable()->after('is_addonable')
                ->comment('Monthly add-on price in RIAL (golden rule 2)');
        });

        Schema::create('plan_module', function (Blueprint $table): void {
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();

            $table->primary(['plan_id', 'module_id']);
        });

        Schema::create('subscription_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained();

            $table->unsignedBigInteger('price')->comment('Rial, snapshotted at purchase');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            $table->unique(['subscription_id', 'module_id']);
            $table->index(['tenant_id', 'subscription_id']);
        });
    }
};
