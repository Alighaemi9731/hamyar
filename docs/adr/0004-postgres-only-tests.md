# ADR 0004 — The test suite runs only against real PostgreSQL

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** Project owner + lead engineer
- **Approved by:** `docs/PROGRESS.md`, 2026-08-07: “ADR 0004 (Postgres-only tests) and ADR 0005 … written and **approved**”, and `docs/ROADMAP.md` task 0.2 marks it “(approved 2026-08-07)”.
- **Supersedes nothing.** Implements the verification half of
  [ADR 0002](0002-single-db-tenancy-rls.md).

## Context

Laravel's default `phpunit.xml` points the test suite at an in-memory SQLite
database. It is the fastest option, it needs no services, and it is what almost every
Laravel project ships with.

It is also unusable here.

MobiShop's central promise is that one shop cannot see another shop's data, and the
mechanism we sell that on is PostgreSQL Row-Level Security: every tenant table carries
`ENABLE ROW LEVEL SECURITY` plus `FORCE ROW LEVEL SECURITY` and a policy
`USING (tenant_id = current_setting('app.tenant_id')::bigint)`, with the application
connecting as a `NOSUPERUSER NOBYPASSRLS` role.

**SQLite has no equivalent of any of that.** It has no row-level security, no
`current_setting`, no roles and no `FORCE` semantics. A suite running on SQLite would
silently skip the entire second defence layer while reporting green.

That is the worst possible failure mode: not a broken test, but a *reassuring* one. The
isolation suite is the artefact the whole tenancy story rests on, and on SQLite it
would be theatre — it would exercise only the Eloquent global scope, which is exactly
the layer we assume can be defeated by a bug.

Secondary but real: Postgres-specific behaviour we depend on (partial indexes, JSONB,
`SELECT … FOR UPDATE` row locks for the `counters` table, `intdiv`-style integer
semantics, composite index planning) either behaves differently on SQLite or does not
exist. Testing on one engine and shipping on another means the test suite is not
testing the product.

## Decision

**Every test runs against a real PostgreSQL 16 instance, using the same non-superuser
role the application uses in production.** There is no SQLite fallback and no
"fast local mode" that swaps the driver.

Concretely:

- `phpunit.xml` sets `DB_CONNECTION=pgsql` and `DB_DATABASE=mobishop_test`.
- The `mobishop_test` database and the `mobishop_app` role are provisioned by
  `docker/postgres/init/10-app-role.sh` for local development, and by an equivalent
  step in `.github/workflows/ci.yml` for CI.
- The test role is `NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS`. It owns the
  tables it migrates, and `FORCE ROW LEVEL SECURITY` is what keeps that ownership from
  becoming a bypass — so tests exercise exactly the enforcement path production
  traffic takes.
- CI runs the isolation suite (`--group=isolation`) as a **separate job** as well as
  within the full run, so a tenancy test that was skipped or filtered out is
  unambiguous in the checks list rather than buried in a summary line.

## Alternatives considered

**SQLite for unit tests, Postgres for feature tests.** Superficially attractive.
Rejected because the split is not stable under maintenance: the moment a "unit" test
grows a database assertion it silently changes engines, and nobody notices until a
Postgres-only behaviour breaks in production. The `tests/Unit` suite instead touches no
database at all (no `RefreshDatabase`), which gets the speed benefit honestly.

**SQLite locally, Postgres in CI.** Rejected for the same reason, plus a worse one: it
makes CI the first place anyone discovers a database-behaviour bug, which is precisely
where feedback is slowest and most expensive.

**Mocking RLS.** Rejected as incoherent — a mock of the mechanism cannot demonstrate
the mechanism works.

## Consequences

- **Positive.** The isolation suite proves something real. A raw-SQL test that bypasses
  Eloquent entirely and still sees no rows is only meaningful on Postgres.
- **Positive.** Row locks, JSONB, partial indexes and index planning behave in tests as
  they do in production.
- **Positive.** CI and local development share one provisioning script's logic, so
  "works on my machine" has one fewer cause.
- **Negative.** Running the suite requires a live Postgres. Mitigated: `make up`
  starts it, and the container is part of the standard dev stack anyway.
- **Negative.** The suite is slower than in-memory SQLite. Measured at ~10s for 112
  tests today; acceptable. If it ever stops being acceptable, the answer is
  parallelisation or a tmpfs data directory — **not** a different engine.
- **Binding.** Changing `DB_CONNECTION` in `phpunit.xml` to anything other than `pgsql`
  requires superseding this ADR.
