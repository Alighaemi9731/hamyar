# Hamyar — Architecture

Companion to the ADRs. Where an ADR explains *why*, this file explains *how it is
wired* and *what you must do when adding code*.

- [ADR 0001 — Stack](adr/0001-stack.md)
- [ADR 0002 — Single database, shared schema, RLS](adr/0002-single-db-tenancy-rls.md)
- [ADR 0003 — Modular monolith + ledgers](adr/0003-modular-monolith.md)

---

## 1. Shape of the system

```
                    ┌───────────────────────────────────────────────┐
  browser (RTL) ───▶│ nginx                                          │
                    └──────────────┬────────────────────────────────┘
                                   ▼
                    ┌───────────────────────────────────────────────┐
                    │ Laravel 12 (PHP-FPM 8.4)                      │
                    │                                               │
                    │  central routes        tenant routes          │
                    │  app.hamyar.ir       <shop>.hamyar.ir         │
                    │  ├─ Filament v4 panel  ├─ Inertia + React     │
                    │  └─ onboarding/billing └─ 16 tenant modules   │
                    └───┬──────────┬───────────┬────────────┬───────┘
                        ▼          ▼           ▼            ▼
                 PostgreSQL 16   Redis 7    Horizon      S3 / MinIO
                 (+ RLS)         cache      workers      per-tenant
                                 session    sms · moadian  prefixes
                                 queue      reports
```

One deployable. The only asynchrony is the queue.

---

## 2. Request lifecycle

1. **nginx** passes everything to `public/index.php`. It does not know about tenants.
2. **`ResolveTenant` middleware** (Phase 1) reads the host:
   - `app.hamyar.ir` (no shop subdomain) → central route group. No tenant context.
   - `demo.hamyar.ir` → looks up `domains.subdomain`, loads the tenant, and binds it
     into the container as the request-scoped `TenantContext`.
   - Unknown subdomain → 404. Never a silent fallback to another tenant.
3. **`select set_config('app.tenant_id', <id>, false)`** is issued on the database
   connection. This is what makes RLS active; see §4. **Session-scoped, not `SET LOCAL`** —
   Laravel does not wrap a request in a transaction, so the transaction-scoped form would
   silently set nothing and every tenant query would return zero rows. Session scope is why
   the value must be cleared at four boundaries (ADR 0007).
4. **`EnsureModuleEnabled`** (Phase 2) 403s if *we* have switched the module off —
   a kill-switch for a module with no provider behind it (ADR 0011), not something a plan
   buys. Since DECISION GATE 6 every module is open to every shop; what a plan sells is
   quantity, enforced at the write by `QuotaGuard` (§4a, ADR 0018).
5. Controller → FormRequest → module Service/Action → Model. A metered action calls
   `QuotaGuard::consume()` **inside the same transaction that writes the row it counts**.
6. **Inertia** renders a React page with the shared props from §6.

Queued jobs run the same context restoration before `handle()` — see §4.3.

---

## 3. Module layout

```
app/Modules/<Name>/
├── Providers/<Name>ServiceProvider.php   routes, views, migrations, bindings
├── Http/
│   ├── Controllers/                      thin — no domain logic
│   ├── Requests/                         all validation lives here
│   ├── Middleware/
│   └── Resources/                        API/Inertia shaping
├── Models/
├── Services/                             domain logic, transactions
├── Actions/                              single-purpose use cases
├── Events/                               the cross-module boundary
├── Listeners/
├── Policies/                             one per resource, always
├── Enums/
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── resources/views/                      Blade (print templates, emails)
├── routes/{web.php,api.php}
└── tests/{Feature,Unit}/
```

Modules are registered by `App\Providers\ModuleServiceProvider`, which discovers
`app/Modules/*/Providers/*ServiceProvider.php` — adding a module needs no edit to
`bootstrap/providers.php`.

### The eighteen modules

| Module | Owns |
|---|---|
| `Platform` | Central data: tenants, plans, modules, subscriptions, billing, SMS credit |
| `Identity` | Users, roles, permissions, 2FA, onboarding, activity log |
| `Catalog` | Categories, brands, products, variants, barcodes, price levels |
| `Inventory` | Branches, warehouses, `product_units` (IMEI), `stock_movements`, transfers, counts |
| `Purchasing` | Suppliers, purchase invoices, bulk IMEI intake, landed costs, GRN |
| `Sales` | Invoices, POS, split payments, returns, quotes, trade-in, Z-report |
| `Repairs` | Tickets, state machine, checklists, parts, technicians, public tracking |
| `CRM` | Parties, contacts, ledger statements, follow-ups, loyalty |
| `Treasury` | Accounts, transfers, expenses, incomes, rentals, daily close, P&L |
| `Cheques` | Received/issued cheques and their status lifecycle |
| `Installments` | Plans, schedules, collection desk, late fees, early settlement |
| `Messaging` | SMS drivers, templates, automations, campaigns, notifications |
| `Reporting` | Reports, dashboards, exports, saved presets |
| `Files` | Media on S3 with per-tenant prefixes and plan quotas |
| `Settings` | Shop profile, fiscal year, print templates, numbering, checklist builder |
| `Hamta` | Guided IMEI ownership-transfer workflow and records |
| `Moadian` | E-invoice adapter and intermediary driver |
| `Storefront` | Public catalogue and reseller price-list links |

### Boundary rules (enforced by `tests/Arch/`)

- A module may **not** reference another module's `Models`, `Http` or `Services`
  namespace directly.
- Cross-module communication is via **domain events** or a **public interface** bound
  in the owning module's service provider.
- `Models`, `Services`, `Actions`, `Events` must not depend on `Http`.
- Every tenant model uses `BelongsToTenant`.
- Controllers must not contain a `DB::` call.

Example of the allowed shape:

```php
// Sales finalises an invoice and announces it. It does not know Messaging exists.
event(new InvoiceFinalised($invoice->id, $invoice->tenant_id));

// Messaging listens. If the module is disabled by plan, the listener no-ops.
class SendInvoiceSms { public function handle(InvoiceFinalised $event): void { … } }
```

---

## 4. Tenancy

### 4.1 The four layers

| Layer | Mechanism | Fails how |
|---|---|---|
| 1. Context | `ResolveTenant` middleware binds `TenantContext` | Unknown subdomain → 404 |
| 2. Eloquent | `BelongsToTenant` global scope + `creating` fill | Query silently scoped |
| 3. Database | RLS policy `USING (tenant_id = current_setting('app.tenant_id')::bigint)` | Rows invisible, writes rejected |
| 4. CI | Two-tenant isolation matrix + raw-SQL tests | Build red |

### 4.2 Writing a tenant table

Every tenant-owned migration does all four of these, in the same file:

```php
Schema::create('repair_tickets', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('tenant_id')->index();
    // … columns …
    $table->index(['tenant_id', 'status']);   // composite, tenant_id first
});

$this->enableRls('repair_tickets');           // policy + FORCE ROW LEVEL SECURITY
```

`php artisan tenancy:check` fails CI if a table has a `tenant_id` column but no RLS
policy, or a model in a tenant module lacks `BelongsToTenant`.

### 4.3 Queues

A job carries the tenant id explicitly:

```php
final class SendDueInstallmentSms implements ShouldQueue
{
    use TenantAware;   // serialises tenant_id, restores context in a `before` hook
}
```

The context is restored **before** `handle()` runs and torn down after, so a worker
process that handles tenant A then tenant B never leaks state between them. This is
covered by a dedicated test (roadmap 1.7).

### 4.4 The escape hatch

`Model::withoutTenancy()` drops the global scope. It is allowed **only** inside the
`Platform` module, and only with a comment stating why. RLS still applies — the
Platform module's central tables simply have no `tenant_id` and therefore no policy.

---

## 5. Data invariants

These are the rules a reviewer checks first, because breaking one is a correctness
bug rather than a style problem.

### Money

- Integer **rial**, column type `BIGINT`. No `decimal`, no `float`, no `/`.
- All arithmetic through `App\Support\Money` (`split()` puts the rounding remainder on
  the last part; `percent()` truncates toward zero).
- Toman is a *display* unit only.

### Stock and balances

Never stored, always derived:

```sql
-- quantity on hand
SELECT SUM(quantity) FROM stock_movements
 WHERE tenant_id = ? AND product_variant_id = ? AND warehouse_id = ?;

-- party balance
SELECT SUM(debit) - SUM(credit) FROM ledger_entries
 WHERE tenant_id = ? AND party_id = ?;
```

If a report gets slow, add an index or a **rebuildable** projection — never a stored
total that becomes the source of truth.

### Serialized units

A phone is a row in `product_units`, not a quantity. Its `status` is a state machine:

```
in_stock ──▶ reserved ──▶ sold ──▶ returned ──▶ in_stock
    │                        │
    └──▶ in_repair ──────────┘
    └──▶ written_off
```

Every transition is recorded with actor, timestamp and reference, so the IMEI passport
can answer *bought from whom → sold to whom → repaired when*. Illegal transitions
throw; they are not silently permitted.

### Dates

Stored as UTC `timestamp`. Rendered through `App\Support\Jalali`, which shifts to
`Asia/Tehran` **before** converting the calendar — converting UTC directly reports the
wrong day for anything after 20:30 Tehran time.

### Numbering

Invoice, ticket and receipt numbers come from the `counters` table with a row lock
(`SELECT … FOR UPDATE`), scoped per tenant and branch. Never `MAX(number) + 1`: it
produces duplicates under concurrency, which is a legal problem on a tax invoice.

---

## 6. Frontend

- **Inertia v2 + React 19 + TypeScript.** No separate API, one session, server-side
  routing.
- **Shared props** (`HandleInertiaRequests`), available on every page:

  ```ts
  {
    auth:     { user: User | null, permissions: string[] },
    tenant:   { id, name, subdomain, settings } | null,
    features: Record<string, boolean>,   // module:<code> and limit:<key>
    flash:    { success?: string, error?: string },
  }
  ```

- **Gating is double.** `EnsureModuleEnabled` guards the route; `features` hides the
  nav item. Hiding UI alone is not authorization.
- **Design system.** Tokens in `resources/css/app.css` under Tailwind v4 `@theme`;
  shadcn/ui base kit with `"rtl": true`; domain components in
  `resources/js/components/domain/`. Full rules in [design-system.md](design-system.md).
- **Filament** renders the central super-admin panel only. It never renders a tenant
  screen, so the two UI paradigms never meet in one page.

---

## 7. Background work

Horizon supervises four queues:

| Queue | Work | Why separate |
|---|---|---|
| `default` | Ledger projections, exports | Latency-tolerant |
| `sms` | Kavenegar/SMS.ir sends | Rate-limited, retried, gateway flaps |
| `moadian` | E-invoice submission and polling | Slow, must not block SMS |
| `reports` | Heavy report and export generation | Long-running |

Iranian gateways go down. Every external call is retried with backoff and, on repeated
failure, surfaced in an error inbox the shop owner can see and resend from — never
silently dropped.

---

## 8. Adding a feature — the checklist

1. Which module owns it? If two do, the second one listens to an event.
2. Migration: `tenant_id`, composite index, `enableRls()` — all three.
3. Model: `BelongsToTenant`.
4. FormRequest for validation; the controller stays thin.
5. Policy + permission named `module.action`.
6. Money as integer rial, dates as UTC, stock/balance as movements.
7. Inertia Resource: money out as `{ amount: int, formatted: string }`.
8. UI from gallery components; logical RTL classes only; new component → `/design` first.
9. Persian strings in `lang/fa/**`.
10. Tests: feature test **and** cross-tenant isolation test.
11. `composer test` green, then tick the roadmap box and log it in PROGRESS.md.
