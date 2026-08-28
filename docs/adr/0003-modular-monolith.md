# ADR 0003 — Modular monolith with ledger-based state

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** Project owner + lead engineer
- **Approved by:** CLAUDE.md **golden rules 3 and 6**, authored by the project owner — the module list and the ledgers-not-totals rule are both stated there as law.

## Context

Hamyar spans eighteen functional areas — from POS to repairs to tax reporting —
built by a very small team. We need internal boundaries strong enough that a change
in Repairs cannot quietly break Sales, without paying the operational cost of
distributed services.

Separately, the domain is financial and inventory-heavy: an auditor (or a shop owner
at 11pm) must be able to ask "why is this number what it is?" and get a defensible
answer.

## Decision

### 1. Modular monolith

Code lives under `app/Modules/<Name>`:

```
Platform  Identity  Catalog     Inventory  Purchasing  Sales
Repairs   CRM       Treasury    Cheques    Installments
Messaging Reporting Files       Settings   Hamta       Moadian  Storefront
```

Each module owns its `Providers`, `Http`, `Models`, `Services`, `Events`, `Policies`,
`database/migrations` and `tests`.

**Cross-module communication happens only through domain events or public service
interfaces.** A module never reaches into another module's models, controllers or
migrations. Pest arch tests enforce this, and also enforce that the domain layer
(`Models`, `Services`, `Events`) does not depend on `Http`.

Deployment stays a single artifact. Queues (Horizon) provide the asynchrony that
would otherwise tempt us toward services.

### 2. Ledgers, never stored totals

Two classes of number are **never** updated in place:

| Question | Answer comes from |
|---|---|
| How many of this product do I have? | `SUM` over `stock_movements` |
| What does this customer owe me? | `SUM` over `ledger_entries` |

Writing a movement or an entry is the only way to change stock or balance. Serialized
phones are stronger still: each is a row in `product_units` whose lifecycle is a
state machine with a recorded history, so an IMEI can always answer *bought from whom
→ sold to whom → repaired when*.

## Alternatives considered

**Microservices.** Rejected outright (and listed as a project anti-goal). Splitting a
POS that must atomically reserve a serialized unit, post a ledger entry, allocate a
counter and write a stock movement into network calls buys distributed transactions
in exchange for nothing this team needs.

**Plain Laravel layout (`app/Models`, `app/Http/Controllers`).** Rejected: at
eighteen functional areas, a flat `app/Models` with ~120 classes has no enforceable
boundaries. Nothing would stop the repairs controller from writing to sales tables.

**Stored balance/quantity columns.** The obvious performance choice, and how most
Iranian competitors do it. Rejected: any crash, race or bug silently corrupts a
number that nobody can reconstruct, and "the stock says 3 but there are 2 on the
shelf" is the single most damaging class of bug in this domain. Reconstructability
wins over read speed.

**Full double-entry general ledger.** Rejected as over-scope (an anti-goal). We keep
purpose-built ledgers with party and account dimensions, which cover the reports the
shops actually ask for, without asking a phone-shop owner to understand accounting.

## Consequences

- **Positive.** Boundaries are testable, so they survive contact with deadlines.
- **Positive.** Every stock and money figure is reconstructible and auditable; a
  reconciliation test can prove a whole seeded month to the rial (Phase 7 DoD).
- **Positive.** If a module ever genuinely needs to be extracted, the seam already
  exists.
- **Negative.** Reads are aggregations, not column lookups. Mitigated with covering
  indexes on `(tenant_id, product_variant_id, warehouse_id)` and friends, a measured
  <300ms budget on a 100k-row seed (Phase 9), and — only if measurement demands it —
  a rebuildable projection table that is never the source of truth.
- **Negative.** Event-based cross-module calls are harder to follow than a direct
  method call. Accepted; the alternative is the coupling we are buying our way out of.
- **Negative.** More boilerplate per module. Mitigated by `php artisan make:module`.
