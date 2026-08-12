<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Providers;

use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Policies\RepairTicketPolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Repairs module.
 *
 * Spec: docs/specs/repairs.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class RepairsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(RepairTicket::class, RepairTicketPolicy::class);
    }
}
