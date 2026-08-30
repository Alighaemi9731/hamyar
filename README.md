# Hamyar — «سامانه همیار»

Multi-tenant SaaS for mobile-phone shops in Iran. POS with serialized IMEI inventory,
a repairs workflow, CRM, cheques, installments, treasury, SMS and reporting.

**Every module is open to every shop.** A plan does not sell access — it sells *how much
work a shop may record in a Jalali month*, metered per action and refilled on the 1st, with
a free first rung so a shop that never pays keeps working
([ADR 0018](docs/adr/0018-metered-plans.md)).

Persian (fa-IR), RTL, Jalali calendar, money in integer rial.

## Quick start

```bash
cp .env.example .env
make build && make up          # php-fpm, nginx, postgres:16, redis:7, minio, mailpit
make install
make artisan CMD="key:generate"
make fresh                     # migrate + seed
npm run dev                    # Vite, on the host
```

| | |
|---|---|
| App | <http://app.localhost> |
| Tenant | `http://<shop>.app.localhost` |
| Design gallery *(dev only)* | <http://app.localhost/design> |
| Mailpit | <http://localhost:8025> |
| MinIO console | <http://localhost:9001> |

`*.localhost` resolves to loopback automatically on macOS and in modern browsers. If
your platform disagrees, see [docs/deploy.md](docs/deploy.md#local-hostnames).

## Quality gate

```bash
make test              # Pint → RTL gate → 7 guards → Larastan L8 → Pest → tenancy check
make test-isolation    # cross-tenant isolation suite only
```

The suite runs **in parallel** (`pest --parallel`), which is the difference between two
minutes and sixteen: each worker gets its own database clone. On a four-core CI runner it
is ~6 minutes for ~1500 tests against a real PostgreSQL.

Nothing merges without this green. Two rules that are load-bearing rather than
stylistic:

- **Tests run on real PostgreSQL, never SQLite** — SQLite has no Row-Level Security,
  so a green SQLite suite would prove nothing about tenant isolation
  ([ADR 0004](docs/adr/0004-postgres-only-tests.md)).
- **Physical direction classes fail the build** — `ml-`, `left-`, `text-left` and
  friends mirror wrongly for RTL users and are invisible to an LTR author
  ([ADR 0005](docs/adr/0005-rtl-direction-class-gate.md)).

## Releasing

```bash
bin/release --dry-run     # print every step, change nothing
bin/release --deploy      # tag → publish → sync → build on the box → cut over → prove
```

One command, and what it refuses to do is the point: it will not tag a commit whose CI is
not green, will not release a version with no `CHANGELOG.md` entry, will not run while a
**green pull request is still sitting unmerged**, and does not call a deploy successful
until `bin/smoke` has confirmed from outside the box — over the real certificate — that the
site is serving that exact version.

`VERSION` and the changelog entry are written in the same pull request as the change they
describe, so a release publishes what `main` already holds rather than adding a commit to
it. Full procedure: [docs/RELEASE_PROCESS.md](docs/RELEASE_PROCESS.md) · version policy:
[docs/VERSIONING.md](docs/VERSIONING.md) · what each release contained and why:
[CHANGELOG.md](CHANGELOG.md).

Is a given fix live? `curl -s https://<apex>/health` answers with the running version, and
needs nothing installed.

> **There is no production server at the moment** (2026-08-29), so `bin/release --deploy`
> and `bin/smoke` are suspended and nothing is being reported as shipped. Work still merges
> on green and `VERSION` still moves in the pull request — which is why the newest tag here
> is older than `VERSION`. Tags are cut by `bin/release`, and a tag in this repository means
> *published*, so none are being fabricated for versions that were never released. They
> resume with the next box.

## Where things are

```
app/Modules/<Name>/        18 modules; cross-module contact only via events
app/Support/               Money (integer rial), Jalali, Digits
resources/js/components/   ui/ = shadcn base kit · domain/ = Money, Num, JDatePicker…
docs/specs/                one spec per module — the source of truth for tests
docs/adr/                  decisions that are expensive to reverse
```

## Documentation

| | |
|---|---|
| **Start here each session** | [docs/ROADMAP.md](docs/ROADMAP.md) |
| What shipped, and why | [docs/PROGRESS.md](docs/PROGRESS.md) |
| How it fits together | [docs/architecture.md](docs/architecture.md) |
| Module specs | [docs/specs/README.md](docs/specs/README.md) |
| Decisions | [docs/adr/README.md](docs/adr/README.md) |
| Testing policy | [docs/testing.md](docs/testing.md) |
| **Why the rules exist** | [docs/lessons.md](docs/lessons.md) |
| Design system | [docs/design-system.md](docs/design-system.md) |
| Deploy & ops | [docs/deploy.md](docs/deploy.md) |
| **Releasing** | [docs/RELEASE_PROCESS.md](docs/RELEASE_PROCESS.md) · [docs/VERSIONING.md](docs/VERSIONING.md) · [CHANGELOG.md](CHANGELOG.md) |

Project rules live in [CLAUDE.md](CLAUDE.md) and take precedence over everything else.
It is deliberately short — every rule is one line, and **why** each one exists is in
[docs/lessons.md](docs/lessons.md), which is where to look before arguing with one.

## Non-negotiables

1. Every tenant table: `tenant_id` + composite index + `BelongsToTenant` + RLS policy.
2. Money is integer rial in `BIGINT`. No floats. Toman is display only.
3. Stock and balances are `SUM`s over movement/ledger tables, never stored totals.
4. A phone is a row in `product_units` with a state machine and a full history.
5. Timestamps stored UTC, rendered Jalali.
6. Every tenant-scoped endpoint ships with a cross-tenant isolation test.
7. A change that is not on the box is not done. Merges are not deploys, and only
   `bin/smoke` knows the difference.

## Licence

Proprietary. All rights reserved.
