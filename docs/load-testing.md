# Load testing — runbook

**Status: run.** First execution 2026-08-20 against staging on `mobiyar.com` — results in
[`docs/load-tests/2026-08-20.md`](load-tests/2026-08-20.md). The aggregate p95 threshold
**failed** (1.62s against 1000ms) with **zero errors**; `/dashboard` is the cause and is
1.3s with a single user, so it is not a concurrency problem.

Two corrections to what follows, both learned by doing it:

- **The seed takes ~88 minutes, not ~19.** The old figure came from a dev machine without
  WAL archiving or `--data-checksums`. Plan the window accordingly.
- **`pg_stat_statements` needs its EXTENSION created**, not just the library preloaded.
  §5 below sends you to it when a run disappoints, and it answered `relation
  "pg_stat_statements" does not exist` the first time. `docker/postgres/init/` now creates
  it; an older box needs `CREATE EXTENSION pg_stat_statements;` once.

## Why not on a developer machine

The app, Postgres, Redis **and the load generator** would share the same cores, through
Docker Desktop's network stack. The result is dominated by the harness, and publishing it
as "p95 under load" is worse than having no number: an optimistic one hides a real
problem, and a pessimistic one sends somebody optimising a virtualised filesystem.

It is also the wrong shape of machine. The thing this test exists to find — FPM worker
starvation, connection-pool contention, a plan that is fine alone and not fine forty times
over — depends on how many cores and how much RAM the box actually has.

> **Never run this against a box that holds a real shop's data.** It signs in as fifty
> shops and reads every hot screen for ninety seconds. Against fixtures that is the point;
> against a live shop it is a self-inflicted incident, and the audit log will record fifty
> owner logins that nobody made.
>
> This sentence was suspended between 2026-08-21 and 2026-08-29, while `mobiyar.com` held
> nothing but seeded fixtures and a second box would have cost money to teach us nothing.
> It is written in its permanent form now, keyed to *what the data is worth* rather than to
> what the box is called, so that the day a paying customer lands there is the day it
> already means the right thing — rather than the day somebody has to remember to
> reinstate it.

> **The seeder must run on a plan that will not stop it.** Fifty shops writing thousands
> of invoices apiece is precisely what `QuotaGuard` exists to refuse (ADR 0018). Seed the
> load-test tenants onto the unlimited plan, or the run measures how fast the product says
> «سقف ماهانه شما پر شده است» — which is a real answer, but not this test's question.

---

## 1. Prepare the staging box

```bash
# ~19 minutes for 50 shops, ~5.5GB. Measured in 11.2; scales linearly.
docker compose -f compose.prod.yaml --env-file .env.production \
  exec -T app_blue php artisan platform:seed-volume --tenants=50 --force
```

Every shop it creates carries a `load-test-` slug, so `--fresh` removes the whole set
afterwards without touching anything else. The seeder only ever **adds**.

Two things the script depends on, both conventions of that seeder:

- hostnames are `load-test-<n>.<APP_DOMAIN>`, resolved through `domains.hostname` — so the
  wildcard DNS record has to be live, not just the apex;
- the owner of shop *n* signs in as `0900<n zero-padded to 7>` / `password`.

## 2. Run it

```bash
docker run --rm -i -v "$PWD/tests/Load:/scripts" grafana/k6 run \
  -e BASE_URL="https://<staging-apex>" \
  -e APP_DOMAIN="<staging-apex>" \
  -e SHOPS=50 \
  /scripts/endpoints.js
```

From a **different machine** to the one under test — a small VPS in the same region, or a
laptop on a decent connection. Running k6 on the box measures the box competing with
itself, which is the mistake this whole document exists to avoid.

`BASE_URL` and `APP_DOMAIN` are usually the same value; they are separate so the script
can be pointed at an IP while still sending the right `Host` header, which is how a
tenant resolves at all (golden rule 1b).

## 3. What counts as a pass

Thresholds are in the script, and they fail the run rather than being read off a chart:

| threshold | value | why that number |
|---|---|---|
| `http_req_duration` p95 | **< 1000ms** | The line between "the software is quick" and "the software is thinking" for someone standing at a counter. Deliberately looser than the 300ms *service* budget, because this figure includes nginx, FPM, boot, session and render. |
| `http_req_failed` | **< 1%** | Errors are not a percentage game here. A 500 under load **is** the finding; the 1% is tolerance for a connection reset, not for a broken screen. |

Per-endpoint `screen_*` trends and `fail_*` rates exist so a slow screen is **named**
rather than averaged away. Read those before the aggregate: one 4-second report hiding
behind nine fast pages is the exact result this is looking for.

## 4. Record it

Write `docs/load-tests/<YYYY-MM-DD>.md` and commit it. The Phase 11 DoD asks for a load
test report in `docs/`, and a number nobody wrote down is a number nobody can compare
against next quarter. Include:

- the **box**: vCPU, RAM, disk type — the numbers mean nothing without it;
- the fixture size (`--tenants=`, and the row count the seeder printed);
- the k6 summary, whole;
- the per-endpoint p95 table;
- anything that failed a threshold, and what was done about it.

## 5. When it fails

Findings feed the three items sitting under 11.2 in the roadmap, and in this order:

1. **N+1s** — the usual cause of a screen that is fine alone and quadratic under
   concurrency. `SENTRY_TRACES_SAMPLE_RATE=1.0` on staging for the duration of the run
   gives a per-request span breakdown; turn it back down afterwards.
2. **Missing composite indexes** — `pg_stat_statements` is preloaded
   (`docker/postgres/postgresql.prod.conf`), so the slowest statements are already being
   recorded. `log_min_duration_statement` is 300ms, so the log has them too.
3. **Queue latency** — Horizon's own dashboard, with the `waits` thresholds already
   configured. A load test that backs the queue up is telling you about the workers, not
   the web tier.

11.2 already spot-checked the *query shapes* at this fixture size with `EXPLAIN (ANALYZE)`
and they held — index scans reading only their own tenant's slice, 1.9–83ms. So a slow
result here is more likely to be about the stack around the query than the query itself,
which is precisely why the endpoint test is a separate measurement from
`ReportLatencyTest`.
