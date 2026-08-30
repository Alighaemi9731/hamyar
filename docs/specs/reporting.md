# Reporting

**Phase 9** · Module `app/Modules/Reporting`

## Purpose

Answering the shop owner's real questions: did I make money this month, which
salesperson is actually selling, what stock is dead, who owes me, and what is due next
week. Every figure must reconcile with the ledgers — Reporting reads, it never invents.

## Data

Owns almost none. It queries the modules' tables through their read models. It owns:

- `saved_filters` — `user_id`, `report_key`, `name`, `filters` (JSON).
- `report_snapshots` — optional cached results for expensive reports, always
  regenerable and never a source of truth.

## Dashboard

Role-aware — a salesperson and an accountant should not see the same screen.

| Widget | Roles |
|---|---|
| Today's sales and profit | Owner, Manager, Accountant |
| Repairs in progress by status | Owner, Manager, Technician |
| Cheques due in 7 days | Owner, Accountant |
| Overdue installments | Owner, Accountant |
| Low stock | Owner, Manager, Warehousekeeper |
| Abandoned devices | Owner, Manager, Technician |
| 30-day sales chart | Owner, Manager |
| Quick actions | All |

## The report catalogue

**Sales** — daily · monthly · by product · by brand · by salesperson · by branch ·
by payment method · returns · quotes conversion.

**Profit** — gross profit by period · by product · by brand · by salesperson ·
serialized unit profit (per IMEI).

**Inventory** — stock on hand · stock valuation · dead stock (no movement in N days) ·
stock movement journal · low stock · serialized units by status · IMEI history export.

**Repairs** — technician performance · average turnaround by status · repair revenue
and parts cost · abandoned devices.

**Financial** — party balances aging (30/60/90) · cheques calendar · installments
book · account statements · expense by category · income by category · P&L ·
cash-flow summary · daily close history.

**Tax** — VAT summary · sales by tax status.

**Operations** — SMS usage and cost · user activity · audit trail.

That is 30+, comfortably over the 25 the roadmap asks for.

## Behaviour

### Filters

Every report takes a **Jalali** date range (with presets: today, this week, this
month, this Jalali year, custom), plus branch, and whatever dimensions it supports.
Ranges convert to UTC bounds via `Jalali::startOfDay` / `endOfDay` — a report that
uses the wrong bound loses or duplicates the last evening's sales.

Consolidated vs per-branch is a toggle, not two reports.

### Output

On screen, print CSS, and Excel export. Money exports as **integer rial** with a
formatted display column, so a spreadsheet never rounds a figure.

Saved filter presets per user.

### Performance

Budget: **< 300ms on a 100k-row seed** for the top ten reports, asserted in CI. No
N+1s. Covering indexes on `(tenant_id, occurred_at, …)`. Where a report genuinely
cannot meet the budget, it is queued and delivered as a file rather than made slow.

### Correctness

Golden-number tests: the seeded "one crazy month" scenario has known expected figures,
and each report asserts them exactly. When a report disagrees with the ledger, the
ledger is right ([ADR 0003](../adr/0003-modular-monolith.md)).

## Screens

Dashboard · report index grouped by area · report viewer with filter bar, saved
presets, print and export.

## Acceptance

- Every report returns the exact expected figures against the seeded scenario.
- Jalali range bounds include the whole Tehran day, at both ends.
- Top-ten reports meet the 300ms budget on a 100k-row seed.
- Excel export matches the on-screen figures.
- Role-aware widgets: a salesperson sees no cost or profit data.
- Cross-tenant isolation on every report endpoint.
- **Quota.** Generating an export spends one `reporting.exports` credit. **Viewing a report
  on screen never does** — the credit buys the file, not the answer, and a shop at its cap
  can still see every figure it could see yesterday (ADR 0018).

## Out of scope

A custom report builder. BI tool integration. Scheduled email report delivery.
