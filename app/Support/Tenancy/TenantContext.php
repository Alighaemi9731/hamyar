<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Platform\Models\Tenant;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * The current tenant, and the database session variable that enforces it.
 *
 * This is layer 1 of the four in [ADR 0002](../../../docs/adr/0002-single-db-tenancy-rls.md).
 * It is bound as a singleton and is the ONLY thing that should write
 * `app.tenant_id` — the RLS policies read it on every query.
 *
 * ## Why `set_config(..., false)` and not `SET LOCAL`
 *
 * `SET LOCAL` is scoped to the enclosing transaction. Outside one it is a silent
 * no-op, and Laravel does not wrap a web request in a transaction — so a `SET LOCAL`
 * issued in middleware would apply to nothing, `current_setting` would come back
 * NULL, and every tenant query would return zero rows. That is a safe failure, but a
 * completely broken application.
 *
 * So we use `set_config('app.tenant_id', $id, false)`, where `false` means
 * session-scoped rather than transaction-scoped. It survives outside transactions and
 * inside them, which is what a request actually needs.
 *
 * The cost of session scope is that the value outlives a single unit of work on a
 * long-lived connection — a queue worker handling tenant A then tenant B. That is
 * handled explicitly rather than hopefully:
 *
 *   · middleware clears the context when the request ends;
 *   · `TenantAware` jobs set it before `handle()` and clear it after;
 *   · `runFor()` always restores the previous value, including when the callback throws.
 *
 * A reconnect would silently drop the GUC, so `TenancyServiceProvider` re-applies it
 * whenever a connection is established.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    /**
     * How many `runAsPlatform()` calls are currently on the stack.
     *
     * The flag is a session variable on one connection, so nesting has to be counted:
     * see `runAsPlatform()` for what happens when it is not.
     */
    private int $platformDepth = 0;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Enter a tenant: remembers it and applies the database session variable.
     */
    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        /** @var int $tenantId */
        $tenantId = $tenant->getKey();

        $this->applyToDatabase($tenantId);

        $this->events->dispatch(new TenantContextChanged($tenant));
    }

    /**
     * Leave the current tenant. Clearing the GUC makes RLS deny everything, which is
     * the correct posture for code that has not declared which tenant it is acting for.
     */
    public function forget(): void
    {
        $this->tenant = null;

        /*
        | The platform depth goes with it. `forget()` is the boundary between units of work
        | — the end of a request, the end of a job, a test's `afterEach` — and a depth left
        | standing from a throw would make the NEXT unit believe it was already inside a
        | platform block and never set the flag at all.
        |
        | Only issued when the depth is actually standing. `forget()` runs at the end of
        | every request, and an unconditional statement here would be the first thing to
        | touch a connection whose transaction some earlier failure had already aborted —
        | turning this into the reporter of other people's errors, several frames from
        | where they happened.
        */
        if ($this->platformDepth > 0) {
            $this->platformDepth = 0;
            $this->setPlatformFlag(false);
        }

        $this->applyToDatabase(null);

        $this->events->dispatch(new TenantContextChanged(null));
    }

    public function has(): bool
    {
        return $this->tenant instanceof Tenant;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        $key = $this->tenant?->getKey();

        return is_int($key) ? $key : null;
    }

    /**
     * The tenant id, or a hard failure.
     *
     * Used by `BelongsToTenant` when filling `tenant_id` on create: a write with no
     * tenant is a bug we want to hear about at the point it happens, not a row that
     * quietly lands with a NULL discriminator.
     */
    public function idOrFail(): int
    {
        $id = $this->id();

        if ($id === null) {
            throw new RuntimeException(
                'No tenant is set. A tenant-scoped write needs a tenant context — '
                .'resolve one from the request, or wrap the call in TenantContext::runFor().'
            );
        }

        return $id;
    }

    /**
     * Run a callback as a given tenant, then restore whatever was in place before.
     *
     * The restore happens in a `finally`, so an exception inside the callback cannot
     * leave the process pinned to the wrong tenant — the single most dangerous state
     * this class can be in.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;

        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $previous instanceof Tenant ? $this->set($previous) : $this->forget();
        }
    }

    /**
     * Run a callback with no tenant at all — RLS will deny every tenant table.
     *
     * Only meaningful for central (Platform) work. Tenant tables are not readable
     * here, by design.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runWithoutTenant(Closure $callback): mixed
    {
        $previous = $this->tenant;

        $this->forget();

        try {
            return $callback();
        } finally {
            if ($previous instanceof Tenant) {
                $this->set($previous);
            }
        }
    }

    /**
     * Run a callback with platform-wide read access to platform-owned tables.
     *
     * Sets `app.platform`, which only the RLS policies on `subscriptions` and the
     * billing tables consult. It does NOT open up ordinary tenant tables — a shop's
     * invoices, customers and stock stay invisible no matter what. The flag is cleared
     * in a `finally`, so an exception cannot leave a process able to read across
     * tenants.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAsPlatform(Closure $callback): mixed
    {
        /*
        | Re-entrant, and it has to be.
        |
        | This used to force the flag OFF in its `finally`, which is correct exactly once:
        | a nested `runAsPlatform()` — an event listener firing inside a platform-context
        | write, say — would clear the flag for the OUTER block that was still running, and
        | every query after it would be denied by RLS. Not with an error: RLS denies by
        | returning nothing, so the symptom is a `findOrFail` on a row that plainly exists,
        | a hundred lines away from the nested call that caused it.
        |
        | Found exactly that way, in Phase 12: an upgrade fired `SubscriptionActivated`,
        | a listener recorded an event inside its own `runAsPlatform()`, and the invoice
        | the caller was in the middle of settling vanished from its own transaction.
        |
        | So the depth is counted and the flag is cleared once, by the outermost caller.
        | Same discipline as `runFor()`, which has always restored the previous tenant
        | rather than clearing it.
        */
        $this->platformDepth++;

        if ($this->platformDepth === 1) {
            $this->setPlatformFlag(true);
        }

        try {
            return $callback();
        } finally {
            $this->platformDepth--;

            if ($this->platformDepth === 0) {
                $this->setPlatformFlag(false);
            }
        }
    }

    private function setPlatformFlag(bool $enabled): void
    {
        $connection = $this->db->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('select set_config(?, ?, false)', ['app.platform', $enabled ? '1' : '']);
    }

    /**
     * Push the current tenant id onto the database session.
     *
     * Public because the service provider re-applies it after a connection is
     * (re)established — a new connection starts with no GUCs at all.
     */
    public function applyToDatabase(?int $tenantId): void
    {
        $connection = $this->db->connection();

        // Only Postgres has the RLS machinery this drives. Guarding keeps the class
        // usable if a future read-replica or reporting connection is not Postgres.
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(
            'select set_config(?, ?, false)',
            ['app.tenant_id', $tenantId === null ? '' : (string) $tenantId]
        );
    }

    /**
     * Re-apply the current context. Called on connection (re)establishment.
     */
    public function reapply(): void
    {
        $this->applyToDatabase($this->id());
    }
}
