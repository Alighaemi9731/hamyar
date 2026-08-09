<?php

declare(strict_types=1);

namespace App\Modules\CRM\Providers;

use App\Modules\CRM\Listeners\CreateDefaultAccount;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Policies\PartyPolicy;
use App\Modules\Platform\Events\TenantProvisioned;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * CRM module.
 *
 * Spec: docs/specs/c-r-m.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class CRMServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Party::class, PartyPolicy::class);

        Event::listen(TenantProvisioned::class, CreateDefaultAccount::class);
    }
}
