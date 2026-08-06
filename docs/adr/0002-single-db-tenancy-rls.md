# ADR 0002 — Single database, shared schema, Postgres RLS

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** Project owner + lead engineer

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
