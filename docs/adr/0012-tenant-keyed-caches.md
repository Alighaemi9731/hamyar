# ADR 0012 — A cache that outlives a request leads its key with the tenant id

- **Status:** **Accepted** — directed capture, 2026-08-18
- **Date:** 2026-08-18
- **Deciders:** Project owner + lead engineer
- **Approved by:** Session directive, 2026-08-18: “*any cache that outlives a request MUST
  lead its key with tenant id — singleton conversion turns per-instance caches into
  cross-tenant surfaces.*”
- **Lineage:** [ADR 0002](0002-single-db-tenancy-rls.md) (single-database tenancy and RLS)
  and its two amendments. This is the third: RLS guards the **database**, and a cache is the
  one place a value can be served **without asking the database at all**.

## Context

Tenancy in this product is a single shared schema with Postgres RLS as the guarantee
(ADR 0002). Every query is scoped, the layer fails closed, and `php artisan tenancy:check`
enforces the shape of it. All of that protects reads that reach Postgres.

**A memo is a read that does not reach Postgres.** That is its entire purpose, and it is
also the one way to return another shop's data without RLS ever being consulted.

This was found the expensive way. `bin/check-forgettable-singletons` — added to make a
different rule mechanical — flagged 87 `forget()` calls on classes that were never bound as
singletons, so every one was a no-op. The obvious fix is to bind them. Doing that to
`PriceResolver` would have introduced a cross-tenant leak:

```php
// Sufficient while every injection produced a fresh instance.
// A leak the moment one instance is shared.
$key = "{$variantId}:{$priceLevelId}:{$at->getTimestamp()}";
```

Variant ids are unique across the whole table, so the key *looks* unambiguous. It is not:
shop A reading variant 42 seeds an entry that shop B can read back by passing
`variant_id=42` and the matching level id in a crafted request. RLS would have returned
nothing. The cache answers with A's price, and **no query runs to contradict it**.

Three ordinary situations serve two tenants from one container, so this is not exotic:

- a **queued job**, where one worker processes tenant A then tenant B;
- a test's **`TenantContext::runFor()`**;
- the **storefront resolving a price-list token**, which enters a tenant *mid-request*
  (ADR 0002's platform escape, used exactly once, by design).

The shape of the mistake is what makes it dangerous: **a leak introduced by a performance
optimisation.** Nothing about adding `singleton()` looks like access control, so nobody
reviews it as access control.

## Decision

**Any cache whose lifetime can exceed a single tenant's turn on the connection must lead
its key with the tenant id.**

Concretely:

1. A class registered with `singleton()` or `scoped()` that holds internal state keys that
   state by tenant — `"{$tenantId}:…"`, tenant first so a key is readable at a glance.
2. The same applies to `Cache::` entries, static properties, and anything memoised on a
   facade root. "Outlives a request" is the test, not "is a singleton".
3. Where a cache genuinely cannot leak — the value is not tenant data, or the key is
   already a tenant-unique surrogate that no request can supply — **say so in a comment at
   the cache key**, naming why. Silence reads as "nobody thought about it", which is the
   state this ADR exists to end.
4. Converting a class to a singleton is a **tenancy review**, not a performance change. The
   diff that adds `singleton()` and the diff that adds the tenant to the key are the same
   diff.

### The audit

Every singleton with internal state names the tenant in its key or justifies why not.
Recorded in `docs/testing.md` under the isolation suite, because that is where somebody
looks when they are already thinking about leaks:

```bash
grep -rn 'singleton(\|scoped(' app/Modules/*/Providers/*.php app/Providers/*.php
# then, for each class: does its cache key start with the tenant?
```

## Consequences

- **A shared memo now costs one string interpolation.** Trivial, and the price of the
  optimisation being safe rather than merely fast.
- **Some caches get worse hit rates**, correctly: entries are no longer shared across
  tenants that were never entitled to share them.
- **`PriceResolver` and `SubscriptionResolver` are now singletons** and their `forget()`
  calls mean something for the first time. `SubscriptionResolver` already keyed by tenant;
  `PriceResolver` did not and now does.
- **The test that proves it took three attempts to become capable of failing** — the
  instant defaulted to `now()`, then the price level defaulted, so the two calls never
  shared a cache key at all. Two green runs in a file written specifically to catch a leak.
  That is recorded in `docs/testing.md` §3 rather than here, but it is the reason this ADR
  says the key must be *inspected*, not assumed: a passing isolation test is not evidence
  the cache was ever consulted.

## Alternatives rejected

**Clear every memo on tenant switch.** `TenantContext::set()` could flush a registry of
caches. Rejected: it makes correctness depend on every future cache remembering to
register, which is the opt-in-and-forget shape this project has now been bitten by three
times (`branch_user` with no writer, `bindIf` versus `bind`, `forget()` on a non-singleton).
A key that carries the tenant is correct by construction and needs no registry.

**Never share caches — forbid singletons with state.** Rejected as too blunt: the memo on
`SubscriptionResolver` is read on every gated route, and `BranchAccess` on every list
screen. Throwing the optimisation away to avoid thinking about the key is a worse trade
than thinking about the key.

**Rely on RLS.** It does not apply. That is the whole finding: a cache hit never reaches
the database, so the guarantee that protects every query does not protect this one.
