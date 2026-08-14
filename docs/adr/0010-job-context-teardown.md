# ADR 0010 — Tenant-context teardown is conditional on how a job entered

- **Status:** **Accepted** — recorded under the autonomous-run authorization of 2026-08-14,
  which directed that this finding be written up at ADR grade.
- **Date:** 2026-08-14
- **Deciders:** Project owner (directed the capture) + lead engineer
- **Supersedes:** nothing. Amends the "around a job" row of [ADR 0007](0007-tenant-session-variable.md).

## Context

ADR 0007 established that `app.tenant_id` is session-scoped on the connection and must be
cleared at four boundaries, one of which is "around a queued job". `RestoreTenantContext`
implemented that boundary as:

```php
try {
    return $context->runFor($tenant, fn () => $next($job));
} finally {
    $context->forget();   // unconditional
}
```

The reasoning was sound for the case it was written for: a worker processes tenant A then
tenant B, and a context surviving the first job would silently apply to the second.

**It is wrong for a job that runs inline.** On the `sync` driver a dispatched job executes
immediately, in-process, *inside whatever context the caller already holds*. Phase 8 made
this reachable: a repair transition dispatches `TicketStatusChanged`, a listener queues an
SMS, and on `sync` that job runs before the transition's caller continues. The unconditional
`forget()` then wiped the caller's tenant, and the next tenant-scoped write failed with
"No tenant is set".

It presented as a bug in a test seeder. It is not: `sync` is a supported driver, it is what
the whole test suite runs on, and a deployment small enough to run without a worker would
hit it in production the first time a repair was marked ready.

There is a second, sharper reason to record this. The same `forget()` call was examined one
commit earlier by a deliberate probe — the middleware was broken on purpose to see which
tests caught it — and the finding was written into the test file as *"removing it fails none
of them; it is belt-and-braces on top of `runFor()`"*. That was accurate and incomplete. The
probe asked whether the call was **necessary**. It did not ask whether the call was
**harmful**, and the honest note it produced made the redundant-looking line easier to leave
alone rather than easier to question.

## Decision

**Teardown is conditional on how the job entered.**

```php
$enteredFromNoTenant = $context->id() === null;

try {
    return $context->runFor($tenant, fn () => $next($job));
} finally {
    if ($enteredFromNoTenant) {
        $context->forget();
    }
}
```

`runFor()` restores the previous context in every case; this decides only whether to wipe
what it restored.

- **On a worker** the context before the job is null, so `forget()` runs and ADR 0007's
  guarantee is intact — the next job cannot inherit a tenant.
- **On `sync`, or any inline execution**, the caller's context is restored and left alone.

## Consequences

- ADR 0007's four boundaries are unchanged in intent. This is a correction to one
  implementation, not a new rule.
- `MessagingTenantIsolationTest` continues to assert the worker case: jobs from two tenants
  interleaved on one worker, each resolving its own wallet and opt-out list. That test was
  verified to fail when jobs are forced onto the wrong tenant.
- The "belt-and-braces" note stays in that file verbatim, including its now-known limit.
  The correction lineage is worth more than a tidy comment: it records that a probe which
  asks only "is this necessary?" will not find a line that is actively wrong.

## The generalisation

**A cleanup that assumes it owns the context it is cleaning must check.** Anything written
as "restore to a known-good state" — clearing a tenant, resetting a connection, flushing a
container binding, restoring a locale — is only safe when the caller had no state to begin
with. Where the same code can run both standalone and nested, teardown must be conditional
on entry, or the nested case destroys its caller.

This applies beyond queues: middleware, test helpers, and any `finally` that resets global
state are the same shape.
