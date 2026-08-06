# Settings

**Phase 1 onward** · Module `app/Modules/Settings`

## Purpose

Everything a shop can configure about itself. Small module, wide reach: almost every
other module reads something from here, so its contract has to be stable and its
defaults have to be sensible enough that a new tenant never has to visit it before
making a sale.

## Data

- `settings` — key/value per tenant, typed and cached. Key namespaces:
  `shop.*`, `fiscal.*`, `print.*`, `numbering.*`, `sales.*`, `repairs.*`,
  `treasury.*`, `display.*`.
- `print_templates` — `type` (`thermal80` · `a5` · `a4` · `repair_receipt` ·
  `installment_contract` · `grn`), header, footer, terms text, logo, per-branch
  override.
- `counters` — `key`, `branch_id`, `period`, `value`. **This is the numbering source
  of truth for the whole product** and is read with a row lock.
- `intake_checklist_templates`, `intake_checklist_items` — the repair intake checklist
  builder ([repairs.md](repairs.md)).

## The settings that matter

| Key | Default | Read by |
|---|---|---|
| `display.currency` | `toman` | `<Money/>` everywhere |
| `display.digits` | `fa` | `<Num/>`, tables, invoices |
| `fiscal.year_start` | 1 Farvardin | Reporting, P&L |
| `sales.vat_enabled` | `false` | Sales — many shops are not registered |
| `sales.vat_rate` | 9 | Sales |
| `sales.allow_negative_stock` | `false` | Inventory |
| `sales.price_override_requires_permission` | `true` | Sales |
| `repairs.approval_cap` | 0 (always require approval) | Repairs |
| `repairs.abandoned_after_days` | 30 | Repairs scheduler |
| `repairs.warranty_days` | 7 | Repairs |
| `treasury.default_cash_account_id` | first cash account | Sales, Treasury |
| `numbering.invoice_format` | `{year}-{seq:4}` | Counters |

`display.currency` deserves emphasis: it changes only what is *rendered*. Storage is
integer rial regardless (golden rule 2), and switching it must never alter a stored
figure — asserted by a test.

## Behaviour

### Counters

```sql
SELECT value FROM counters
 WHERE tenant_id = ? AND key = ? AND branch_id = ? AND period = ?
   FOR UPDATE;
```

Increment inside the caller's transaction. Per tenant **and** branch, optionally reset
per period (year, or Jalali fiscal year). `MAX(number) + 1` is forbidden — it produces
duplicates under concurrency, which on a tax invoice is a legal problem, not a bug
report.

### Caching

Settings are read on nearly every request, so they are cached per tenant and
invalidated on write. The cache key includes the tenant id; a cache that leaks across
tenants would be a tenancy bug with none of the RLS protections
([ADR 0002](../adr/0002-single-db-tenancy-rls.md)).

### Fiscal year

Jalali. Reporting presets ("this year") and counter period resets both derive from it.

### Print templates

Logo, header, footer, terms text and paper size, with per-branch overrides. Rendered
through `PrintLayout.*` ([design-system.md](../design-system.md)) — never a page-local
print hack.

## Screens

Shop profile · display preferences · fiscal year · numbering formats · print template
editor with live preview · intake checklist builder · module toggles (within what the
plan allows).

## Events

Emits: `SettingChanged`, `CounterAllocated`, `PrintTemplateUpdated`.

## Acceptance

- Counters allocate with no gaps or duplicates under 50 concurrent requests.
- Counters are independent per branch and reset per period as configured.
- Changing `display.currency` alters rendering only — no stored value moves.
- The settings cache is per tenant and never serves another tenant's value.
- Print templates render correctly at thermal 80mm, A5 and A4.
- A new tenant can complete a sale without visiting settings at all.
- Cross-tenant isolation on every endpoint.

## Out of scope

Per-user preferences beyond theme. Multi-language UI (the product is Persian).
Workflow customisation beyond the intake checklist.
