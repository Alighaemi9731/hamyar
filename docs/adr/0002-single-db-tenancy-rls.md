# ADR 0002 — Single database, shared schema, Postgres RLS

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** Project owner + lead engineer
- **Approved by:** CLAUDE.md **golden rule 1**, authored by the project owner. Amended and re-confirmed at [DECISION GATE 1](../ROADMAP.md) (2026-08-07), which approved the `set_config` refinement in [ADR 0007](0007-tenant-session-variable.md) and rewrote golden rule 1 to match.

## Context

MobiShop expects **thousands of small tenants** — an average tenant is a shop with
2–5 users, a few thousand invoices a year and a few hundred megabytes of media. A
tenant leaking another tenant's data would be an existential failure: this data
includes customer national-ID scans, device unlock passcodes and full financial
records.

We need an isolation model that is (a) cheap to operate at that tenant count, and
(b) defensible even when the application code has a bug.

## Decision

**Single database, shared schema.** Every tenant-owned table carries a `tenant_id`
column with a composite index `(tenant_id, …)`. On top of that we run four layers
of defence:

1. **`TenantContext`** — middleware resolves the tenant from the subdomain and pins
   it for the request. Requests without a tenant subdomain hit central routes only.
2. **`BelongsToTenant` trait** — a global Eloquent scope plus a `creating` hook that
   fills `tenant_id`. Escaping it (`withoutTenancy()`) is allowed only inside the
   Platform module and must carry a comment explaining why.
3. **PostgreSQL Row-Level Security** — every tenant table gets
   `ENABLE ROW LEVEL SECURITY` **and** `FORCE ROW LEVEL SECURITY`, with a policy
   `USING (tenant_id = current_setting('app.tenant_id')::bigint)`. Each request and
   each queued job issues `SET LOCAL app.tenant_id` inside its transaction.
4. **Automated isolation tests in CI** — a two-tenant matrix over every tenant-scoped
   endpoint, plus raw-SQL tests that bypass Eloquent entirely and still see nothing.

The role model behind layer 3 is worth stating precisely, because getting it wrong
turns RLS into decoration. PostgreSQL exempts two kinds of role from a policy:

1. **superusers and `BYPASSRLS` roles** — always exempt, unconditionally;
2. **the table owner** — exempt *unless* the table declares `FORCE ROW LEVEL SECURITY`.

So we do both things that close those holes:

| Role | Superuser | Used by |
|---|---|---|
| `mobishop_app` | **No** (`NOSUPERUSER NOBYPASSRLS`) | Everything: requests, queue workers, migrations, seeders, tests |
| `mobishop` | Yes | Infrastructure only — `make psql`, backups, manual surgery |

`mobishop_app` owns the tables it migrates, which would normally exempt it — but
`enableRls()` always emits `FORCE ROW LEVEL SECURITY`, so the policy applies to the
owner too. The result is one connection for every context (no privilege juggling
between migrating and serving) while the test suite exercises exactly the same
enforcement path as production traffic.

The superuser is a documented foot-gun: anyone querying through `make psql` sees all
tenants, by design, because backups and incident response need that.

## Alternatives considered

**Schema per tenant.** Better perceived isolation, but every migration has to run
across thousands of schemas that inevitably drift out of version-sync; Postgres
connection and catalogue overhead grows with schema count; cross-tenant analytics
(MRR, churn, usage) becomes a union over thousands of schemas. Rejected.

**Database per tenant.** Strongest isolation and the easiest per-tenant restore, but
absurdly expensive for tenants paying a small monthly fee, and it makes connection
pooling a project of its own. Rejected for the general case — kept open as a
**hybrid** path for a future VIP/enterprise tier, which is why no application code is
allowed to assume "one database" beyond the `tenant_id` column.

**Application-scope only, no RLS.** This is what most Laravel multi-tenancy tutorials
do. Rejected: a single forgotten `->withoutGlobalScopes()`, a raw query, or a
hand-written report SQL is enough to leak everything, and reporting is a large part of
this product.

## Consequences

- **Positive.** One migration run, one backup, one connection pool. Global analytics
  are a plain `GROUP BY tenant_id`. Onboarding a tenant is an `INSERT`.
- **Positive.** An application-layer bug alone cannot leak cross-tenant data; the
  database refuses.
- **Negative.** Every tenant table must remember `tenant_id`, the trait, the composite
  index and the RLS migration. Mitigated by `php artisan tenancy:check`, which fails
  CI when any of the four is missing (Phase 1.3).
- **Negative.** `SET LOCAL` is transaction-scoped, so anything running outside an
  explicit transaction needs the context re-applied. The context service hooks
  connection and transaction events; queued jobs serialise the tenant id and restore
  it before `handle()`. Both are covered by tests.
- **Negative.** A per-tenant point-in-time restore means extracting rows by
  `tenant_id`, not restoring a file. Documented in [docs/deploy.md](../deploy.md).
- **Negative.** Noisy-neighbour risk on shared tables. Accepted at this scale;
  `sms_messages` and `stock_movements` are designed for future monthly partitioning.

## Amendment (2026-08-08): the platform escape hatch

Billing broke the symmetry. `subscriptions` and `subscription_invoices` carry a
`tenant_id`, but the Platform module has to read *across* every shop to compute MRR and
churn, and a shop must never write its own subscription row. A plain tenant policy makes
the first impossible; exempting the tables from RLS entirely gives up the second.

So those two tables get a policy with one extra disjunct:

```sql
tenant_id = current_setting('app.tenant_id', true)::bigint
  OR current_setting('app.platform', true) = '1'
```

`app.platform` is set by exactly one method, `TenantContext::runAsPlatform()`, which
clears it in a `finally`. Three properties make this safe rather than a back door:

- **Narrow.** Only the billing policies consult the flag. Ordinary tenant tables ignore
  it, so a platform context still sees zero users, zero invoices, zero stock. Asserted
  directly in `PlatformBillingIsolationTest`.
- **Explicit.** Every cross-tenant read is a visible `runAsPlatform()` call in the
  source. There is no ambient mode and nothing is opened by default.
- **Still enforced.** These tables remain RLS-protected and `tenancy:check` still
  requires it. What they are exempt from is the *Eloquent* trait — listed by name in
  `PLATFORM_OWNED_TABLES`, which buys no relief from the database policy.

The alternative we rejected was adding the tables to `EXEMPT_TABLES`, which would have
been one line. It would also have removed the database's protection from the two tables
that record what every customer owes us, on the strength of a promise that application
code always remembers a `where` clause.

## Amendment (2026-08-08): billing child tables

`subscription_addons` and `payment_attempts` shipped carrying only a foreign key to
their parent. Because `tenancy:check` keys off a non-nullable `tenant_id`, it could not
see them, and neither had a policy. The reasoning at the time was that they are only
reachable through an RLS-protected parent.

That is true, and it stops being true the moment a tenant-facing billing endpoint looks
one up by its own id — which Phase 2.4 was about to add. An invariant that holds only
until the next feature is not a boundary; it is a deadline.

Both now carry a denormalised `tenant_id`, backfilled from the parent, with a composite
index and the same FORCE RLS + `app.platform` policy as their parents
(`2026_08_08_000020_add_tenant_id_to_billing_child_tables`). The denormalisation is
chosen over a policy that joins the parent: a join in a policy runs per row on every
query and cannot use an index.

The general lesson, worth applying to future tables: **reachability is not protection.**
If a table can be named in a query, it needs its own policy.

## Amendment (2026-08-18): a policy operator the planner cannot use

The two amendments above are about *which rows* a policy admits. This one is about what
a policy costs, and it is the failure mode with no symptom at all.

**An RLS predicate is ANDed into every query against its table.** That is the whole
point — it is why the layer fails closed and why no `where` clause can be forgotten. It
also means the policy's predicate is part of every query plan, and a predicate the
planner cannot use is a predicate that defeats every index on the table.

`activity_log` holds central rows (a platform admin acting on a shop) beside tenant
ones, so it was given the null-tolerant policy, written the obvious way:

```sql
tenant_id IS NOT DISTINCT FROM current_setting('app.tenant_id', true)::bigint
```

`IS NOT DISTINCT FROM` is the one operator that means "equal, and NULL counts as equal
to NULL" — exactly the semantics wanted. **No btree can serve it.** From the moment that
policy was created in Phase 2, `activity_log` had no usable index: not the
`(tenant_id, created_at)` index shipped alongside the column, and not the two added
carefully for the 11c viewer's filter set. Every query sequentially scanned the entire
table — the whole platform's history — to answer a question about one shop, and got a
little slower with every shop that signed up.

Nothing errored. No test failed. `\d activity_log` listed four healthy indexes and the
query planner quietly ignored all of them. It is the same family as the `bindIf` and
`function_exists` traps in CLAUDE.md: **not a crash, just the wrong thing winning
silently.**

Two changes, both measured on a seeded 1.8M rows (fifty shops, a year of history):

1. **`EnablesRowLevelSecurity` emits an indexable OR** for the null-tolerant case —
   `(tenant_id = current OR (tenant_id IS NULL AND current IS NULL))`. Identical
   semantics; the equality branch is something an index can be entered on.
2. **The model carries a global scope naming its tenant**, the way `BelongsToTenant`
   does for every other model. RLS remains the security boundary and would still enforce
   isolation with the scope deleted; the scope exists so the **planner** gets a plain
   `tenant_id = 4`. Postgres cannot fold `current_setting()` at plan time, but the
   application knows the answer before it builds the query, so it says so.

| predicate                                   | plan                | time     |
|---------------------------------------------|---------------------|----------|
| `IS NOT DISTINCT FROM` (RLS alone)          | Parallel Seq Scan   | 55.8 ms  |
| indexable OR (RLS alone)                    | BitmapOr + sort     | 112 ms   |
| indexable OR **+ the model's tenant scope** | Index Scan Backward | 0.6 ms   |

The milliseconds are not the finding. The finding is that before the fix, **every query
cost the whole platform**, and after it every query costs one shop's slice — the numbers
stop growing when a fifty-first shop signs up.

**The general lesson, alongside "reachability is not protection":** *a policy is part of
every plan.* When writing or changing one, ask what operator it puts in front of the
planner, and `EXPLAIN` a realistic volume as the **application role with `app.tenant_id`
set** — a superuser bypasses RLS entirely and will happily show you a plan the
application will never get.

### Null-tolerance is kept, deliberately

The last ordered-scan plan is still available, and the only way to reach it is a policy
with no OR at all — which requires `tenant_id` never to be NULL, which means a platform
action on a shop would have to be recorded somewhere the shop cannot see.

**That trade was declined.** A platform staff member impersonating a shop's owner is
precisely the event that shop has the strongest interest in reading, and
`ImpersonationService::record()` already writes it inside `runFor($tenant)` so that it
lands in their log rather than a central one. Transparency about what we do to a
customer's account is worth more than 0.074ms.

Because that is now a *decision* rather than an implementation detail, it is asserted:
`ActivityLogViewerTest` requires an impersonation entry to be visible to the tenant's own
Owner in their own viewer. Moving that `record()` call outside `runFor` would make the
row central and invisible, and would fail the test that says why it must not be.

## Related

The role model above is only meaningful if the test suite exercises it, which is why
[ADR 0004](0004-postgres-only-tests.md) forbids SQLite outright: on SQLite the entire
RLS layer silently disappears and the isolation suite would prove nothing.
