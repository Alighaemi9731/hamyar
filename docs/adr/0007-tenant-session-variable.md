# ADR 0007 — `set_config(..., false)` rather than `SET LOCAL` for `app.tenant_id`

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** Lead engineer (raised at Decision Gate 1)
- **Refines** [ADR 0002](0002-single-db-tenancy-rls.md).

## Context

CLAUDE.md golden rule 1 says each request and job runs `SET LOCAL app.tenant_id`, and
the RLS policies read that setting on every query. Implementing it literally does not
work.

**`SET LOCAL` is scoped to the enclosing transaction.** Outside one it is a no-op —
Postgres raises a warning and discards it. Laravel does not wrap a web request in a
transaction, and it should not: holding a transaction open for the lifetime of an HTTP
request would serialise the application behind long-lived locks.

So a literal `SET LOCAL` in middleware would set nothing. `current_setting('app.tenant_id', true)`
would return NULL, every policy predicate would evaluate false, and every tenant query
would return zero rows. The application would fail safely and completely.

Wrapping every request in a transaction to make `SET LOCAL` work was considered and
rejected: it converts a read-heavy web app into one long write transaction, blocks on
any slow external call inside the request, and makes the connection pool the
bottleneck.

## Decision

Use **`select set_config('app.tenant_id', $id, false)`**, where the third argument
`is_local = false` makes the setting **session-scoped** rather than
transaction-scoped. It applies inside and outside transactions, which is what a
request actually needs.

Session scope means the value outlives a single unit of work on a long-lived
connection — a queue worker handling tenant A and then tenant B. That is the one real
risk this choice introduces, and it is handled explicitly rather than hopefully:

| Boundary | Mechanism |
|---|---|
| End of request | `ResolveTenant` clears the context in a `finally` |
| Around a job | `TenantAware` + `RestoreTenantContext` set before `handle()`, clear after |
| Nested/temporary switch | `TenantContext::runFor()` restores the previous value in a `finally` |
| New or reconnected connection | `TenancyServiceProvider` re-applies on `ConnectionEstablished` |

The last row matters more than it looks: a new connection starts with no settings at
all, so a reconnect mid-request would otherwise silently drop the tenant and make
every subsequent query return nothing.

Two properties keep the failure mode safe:

- Policies read `current_setting('app.tenant_id', true)` — `missing_ok = true` — so an
  unset variable yields NULL rather than an error, and `tenant_id = NULL` is never
  true. **Unset means "see nothing", not "see everything".**
- The Eloquent global scope applies `1 = 0` when there is no tenant, so both layers
  deny by default independently.

`tests/…/QueuedJobTenancyTest.php` asserts the worker case directly, including that a
worker is left with no tenant after each job and that a tenant-aware job dispatched
with no context fails loudly instead of silently doing nothing.

## Alternatives considered

**Literal `SET LOCAL` plus a transaction per request.** Rejected — see above.

**A connection per tenant.** Clean isolation, but connection count grows with
concurrent tenants and Postgres connections are expensive; it also defeats pooling.

**Passing the tenant id as a bound parameter into every query.** That is what the
Eloquent scope already does, and it is precisely the layer we assume can be bypassed
by a bug. The point of RLS is to be enforced *below* application code.

## Consequences

- **Positive.** RLS works in the ordinary, non-transactional request path — which is
  every request.
- **Positive.** Works unchanged inside transactions, so a POS finalisation that opens
  one is protected identically.
- **Negative.** Correctness now depends on clearing the context at each boundary. Four
  mechanisms cover the four boundaries and each has a test; a fifth boundary added
  later without one would be a real hole.
- **Negative.** Under Laravel Octane the container survives between requests, which
  makes the middleware's `finally` clear load-bearing rather than merely tidy. Noted
  here because Octane is on the table for Phase 11.
- **Documentation.** CLAUDE.md golden rule 1 still says "SET LOCAL". It should be read
  as *"the tenant id is pinned on the connection for the scope of the work"*; this ADR
  is the precise statement. Worth amending the wording at the next CLAUDE.md revision.
