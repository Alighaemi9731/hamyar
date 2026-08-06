# ADR 0001 — Technology stack

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** Project owner + lead engineer

## Context

MobiShop is a multi-tenant SaaS for Iranian mobile-phone shops: POS, serialized IMEI
inventory, repairs, CRM, cheques, installments, treasury, SMS and reporting. The
target is thousands of small tenants, built and operated by a very small team, sold
into the Iranian market (Persian UI, RTL, Jalali calendar, IRR money, local payment
and SMS gateways, local hosting).

The stack has to optimise for three things, in this order:

1. **Time to a sellable product** with one or two engineers.
2. **Availability of local integrations** — Zarinpal, Kavenegar/SMS.ir, Jalali dates,
   Moadian (tax) intermediaries — and of maintainers who can take this over.
3. **Operational simplicity** on a single strong VPS to start with.

## Decision

| Layer | Choice |
|---|---|
| Language / framework | PHP 8.4 + Laravel 12 |
| Database | PostgreSQL 16 |
| Cache / session / queue | Redis 7 + Laravel Horizon |
| Tenant UI | Inertia.js v2 + React 19 + TypeScript + Tailwind CSS v4 (RTL) |
| Super-admin UI | Filament v4 (central panel only) |
| Feature flags | laravel/pennant, mapped to plan + add-ons |
| Authorization | spatie/laravel-permission with `teams = tenant_id` |
| Payments | shetabit/multipay, Zarinpal driver first |
| Dates | morilog/jalali (render-only; storage is UTC) |
| Files | spatie/laravel-medialibrary on S3-compatible storage |
| Audit | spatie/laravel-activitylog |
| Excel | maatwebsite/excel |
| Barcodes / QR | picqer/php-barcode-generator, bacon/bacon-qr-code |
| Tests | Pest v4 (+ arch plugin) |
| Static analysis | Larastan level 8 |
| Style | Laravel Pint |
| CI | GitHub Actions |

## Alternatives considered

**NestJS + Next.js.** Strong runtime performance and a single language across the
stack. Rejected because every SaaS primitive we need — tenancy, permissions with
teams, queues with a supervisor UI, feature flags, an instant admin panel, Iranian
payment/SMS packages — has to be assembled by hand. For a team this size that is
weeks of glue code that Laravel gives us on day one. If the team ever becomes fully
TypeScript, this is the sane migration target; the architecture (modules, ledgers,
RLS) is deliberately framework-independent.

**Django.** Comparable batteries and a good admin. Rejected mainly on multi-tenancy:
the dominant package (`django-tenants`) is schema-per-tenant, which is exactly the
model we reject in [ADR 0002](0002-single-db-tenancy-rls.md) for thousands of small
tenants. The Iranian package ecosystem (gateways, SMS, Jalali) is also thinner.

**MySQL instead of PostgreSQL.** Rejected: we depend on Row-Level Security as the
second line of tenancy defence, which MySQL does not have. Partial indexes, JSONB and
future declarative partitioning (for `sms_messages` and `stock_movements`) also
matter at our expected row counts.

**A separate API + SPA.** Rejected: two deployables, two auth stories, and CORS/token
plumbing, for no benefit — there is no third-party API consumer in scope. Inertia
gives us an SPA feel with server-side routing and one session.

## Consequences

- **Positive.** Very fast delivery of a broad feature surface; a large Persian-speaking
  Laravel community to hire from; every local integration already has a package;
  one artifact to deploy.
- **Negative.** PHP-FPM throughput is lower than a Node or Go equivalent. Accepted:
  the workload is small-tenant CRUD, and Laravel Octane is available if a single
  strong VPS ever stops being enough (see [docs/deploy.md](../deploy.md)).
- **Negative.** Filament and Inertia are two different UI paradigms in one repo.
  Contained by scope: Filament is central-panel-only and never renders tenant screens.
- **Binding.** This stack is locked. Swapping any row of the table above requires a
  new ADR and the project owner's approval (CLAUDE.md, "Stack (locked)").
