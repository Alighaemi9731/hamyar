<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextChanged;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

/**
 * Wires the tenant context into the two subsystems that cannot ask for it themselves.
 *
 * See [ADR 0002](../../docs/adr/0002-single-db-tenancy-rls.md) for the four layers;
 * this class keeps layers 1 and 3 in sync with each other.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton, not scoped: a queue worker keeps one container for many jobs, and
        // the context is switched explicitly per job by the TenantAware trait. A
        // scoped binding would quietly hand each job a fresh, empty context and then
        // every tenant query would return nothing.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        $this->reapplyOnReconnect();
        $this->syncPermissionTeam();
    }

    /**
     * `app.tenant_id` is a session variable on a specific connection. A new connection
     * — first use, a reconnect after the database restarted, a second connection for
     * reads — starts with none, and every RLS policy would then deny everything.
     *
     * Re-applying on ConnectionEstablished makes the context survive that, so a
     * dropped connection mid-request is a blip rather than a wall of empty pages.
     */
    private function reapplyOnReconnect(): void
    {
        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() !== 'pgsql') {
                return;
            }

            $context = $this->app->make(TenantContext::class);

            if ($context->has()) {
                $context->reapply();
            }
        });
    }

    /**
     * spatie/laravel-permission scopes roles by a "team id". Ours is the tenant id
     * (CLAUDE.md golden rule 1).
     *
     * This listens to every context change rather than hooking the registrar's first
     * resolution: a queue worker switches tenants repeatedly inside one container, and
     * a team id captured once would evaluate every later job's permissions against the
     * first job's shop. It also resets spatie's in-memory permission cache, which is
     * keyed per team and would otherwise answer from the previous tenant's data.
     */
    private function syncPermissionTeam(): void
    {
        Event::listen(function (TenantContextChanged $event): void {
            $registrar = $this->app->make(PermissionRegistrar::class);

            $registrar->setPermissionsTeamId($event->tenantId());
            $registrar->forgetCachedPermissions();
        });
    }
}
