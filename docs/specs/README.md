# Hamyar — Module specifications

One file per module. **These are the source of truth for acceptance tests**: when a
spec and an implementation disagree, the spec is right until it is deliberately
changed here.

Each spec follows the same shape:

1. **Purpose** — one paragraph on what the module is for and who uses it.
2. **Data** — the tables it owns, with the columns that carry meaning.
3. **Behaviour** — the rules, state machines and calculations.
4. **Screens** — what the user sees.
5. **Events** — what it emits and listens to (the only cross-module surface).
6. **Acceptance** — the checks a phase's tests must satisfy.
7. **Out of scope** — what this module deliberately does not do.

## Index

| Module | Spec | Phase |
|---|---|---|
| Platform | [platform.md](platform.md) | 2 |
| Identity | [identity.md](identity.md) | 1 |
| Catalog | [catalog.md](catalog.md) | 3 |
| Inventory | [inventory.md](inventory.md) | 3 |
| Purchasing | [purchasing.md](purchasing.md) | 3 |
| CRM | [crm.md](crm.md) | 4 |
| Sales | [sales.md](sales.md) | 5 |
| Installments | [installments.md](installments.md) | 5 · 7 |
| Installment collection | [installment-collection.md](installment-collection.md) | 7 |
| Repairs | [repairs.md](repairs.md) | 6 |
| Treasury | [treasury.md](treasury.md) | 7 |
| Cheques | [cheques.md](cheques.md) | 7 |
| Messaging | [messaging.md](messaging.md) | 8 |
| Reporting | [reporting.md](reporting.md) | 9 |
| Storefront | [storefront.md](storefront.md) | 10 |
| Hamta | [hamta.md](hamta.md) | 10 |
| Moadian | [moadian.md](moadian.md) | 10 |
| Files | [files.md](files.md) | 3+ |
| Settings | [settings.md](settings.md) | 1+ |

## Rules that apply to every module

These are golden rules from `CLAUDE.md`, repeated because every spec assumes them:

- **Tenancy.** Every tenant table has `tenant_id`, a composite index starting with it,
  the `BelongsToTenant` trait and an RLS policy — all four, in the same migration.
- **Money.** Integer rial, `BIGINT`. No floats. Toman is display only.
- **Ledgers.** Stock quantity and balances are `SUM`s over `stock_movements` and
  `ledger_entries`. Never a stored total.
- **Dates.** Stored UTC, rendered Jalali via helpers.
- **Boundaries.** Cross-module contact is by domain event or a bound public interface.
- **Gating.** Module availability comes from the plan via Pennant, enforced on the
  route *and* reflected in the nav.
- **Numbering.** Invoice, ticket and receipt numbers come from the `counters` table
  with a row lock — never `MAX(number) + 1`.
- **Tests.** Feature tests plus a cross-tenant isolation test for every endpoint.
