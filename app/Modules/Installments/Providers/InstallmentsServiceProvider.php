<?php

declare(strict_types=1);

namespace App\Modules\Installments\Providers;

use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Policies\InstallmentPlanPolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Installments module.
 *
 * Spec: docs/specs/installments.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class InstallmentsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(InstallmentPlan::class, InstallmentPlanPolicy::class);
    }
}
