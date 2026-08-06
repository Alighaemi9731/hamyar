# MobiShop

Multi-tenant SaaS for mobile-phone shops in Iran. POS with serialized IMEI inventory,
a repairs workflow, CRM, cheques, installments, treasury, SMS and reporting — sold as
plans with individually purchasable modules.

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
make test              # composer test: Pint → RTL gate → Larastan L8 → Pest
make test-isolation    # cross-tenant isolation suite only
```

Nothing merges without this green. Two rules that are load-bearing rather than
stylistic:

- **Tests run on real PostgreSQL, never SQLite** — SQLite has no Row-Level Security,
  so a green SQLite suite would prove nothing about tenant isolation
  ([ADR 0004](docs/adr/0004-postgres-only-tests.md)).
- **Physical direction classes fail the build** — `ml-`, `left-`, `text-left` and
  friends mirror wrongly for RTL users and are invisible to an LTR author
  ([ADR 0005](docs/adr/0005-rtl-direction-class-gate.md)).

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
| Design system | [docs/design-system.md](docs/design-system.md) |
| Deploy & ops | [docs/deploy.md](docs/deploy.md) |

Project rules live in [CLAUDE.md](CLAUDE.md) and take precedence over everything else,
including the generated Laravel guidance at the bottom of that file.

## Non-negotiables

1. Every tenant table: `tenant_id` + composite index + `BelongsToTenant` + RLS policy.
2. Money is integer rial in `BIGINT`. No floats. Toman is display only.
3. Stock and balances are `SUM`s over movement/ledger tables, never stored totals.
4. A phone is a row in `product_units` with a state machine and a full history.
5. Timestamps stored UTC, rendered Jalali.
6. Every tenant-scoped endpoint ships with a cross-tenant isolation test.

## Licence

Proprietary. All rights reserved.
