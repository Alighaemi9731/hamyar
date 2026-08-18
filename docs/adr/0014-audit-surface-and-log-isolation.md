# ADR 0014 — What the audit log records, and why its policy had to change shape

**Status:** accepted · Phase 11c · decided 2026-08-18

## Context

The roadmap described 11c as *"a read-only UI over data `spatie/activitylog` has
collected since Phase 2."* That premise was wrong, and checking it was the first thing
11c did.

Across eighteen modules, exactly one model carried `LogsActivity` — `Identity\User`,
logging `name`, `email`, `mobile`, `is_active` — beside two hand-written call sites:
an impersonation starting, and a device passcode being revealed. The development
database's entire audit trail was two rows, both «user created».

So the question the viewer exists to answer — «کی این قیمت را عوض کرد؟», a weekly
support call at fifty evaluators — had **no row to find**. A filter bar over that table
would have worked perfectly and answered nothing, and the checkbox would have claimed
otherwise.

Three decisions follow: what earns an audit entry, how a secret is kept out of one, and
what to do about a tenant-isolation policy that turned out to disable every index on
the table.

## Decision 1 — What earns an entry

**A change earns an audit entry when an owner would ask who made it, and no other table
already answers.** The second half is what keeps the log small enough to read.

Audited (`App\Support\Audit\Auditable`, a thin wrapper over `LogsActivity`):

| subject | why |
|---|---|
| `Catalog\Product`, `Catalog\ProductVariant` | the catalogue is edited by everyone and blamed by everyone |
| `Catalog\PriceLevel` | renaming or re-defaulting a level re-prices the whole shop |
| `CRM\Party` | the credit limit is the number a shop argues about |

Audited by hand, because the automatic form would answer the wrong question:

| subject | why |
|---|---|
| `Catalog\ProductPrice` → logged **against the variant** | see below |
| `Repairs\RepairTicket` (passcode reveal, since Phase 6) | the protection is not that nobody can read the code, it is that nobody can read it invisibly |

**Not audited, deliberately:**

- **`stock_movements`, `ledger_entries`, `product_unit_histories`,
  `ticket_status_histories`, `cheque_events`.** These are already the record. Each row
  carries an actor and a timestamp and is never updated in place (golden rule 3), so an
  activity entry beside it would duplicate the highest-volume writes in the product to
  say something already written down. `ticket_status_histories` is the clearest case: a
  repair moves through six statuses, and mirroring that into `activity_log` would make
  repairs the largest subject in the table while adding nothing.
- **Invoices and their lines.** Same argument, plus these are immutable once issued.

### Prices are logged against the variant, not the price row

`product_prices` is append-only: a change inserts a row, so *what* and *when* are
already recorded and only *who* is missing. Two details of how that gap is filled:

**The subject is the variant.** A `ProductPrice` exists for one moment and is never
touched again, so its own history would hold a single entry. The thing a shopkeeper
opens a history *for* is the phone — its name changed here, its barcode there, its
price three times since Farvardin — and pointing the entry at the variant puts all of
that on the screen they were already going to look at.

**The opening price is not a change.** With no previous row there is nothing to compare,
and logging it would write one entry per variant per level on every first import —
thousands of rows saying nothing has happened yet, burying the ones that have.

## Decision 2 — The log masks what the model masks

An audit log is the shortest path around every other protection a secret has. A repair
ticket's `device_passcode` is encrypted at rest, hidden from serialisation, gated by a
permission and audited on reveal; `LogsActivity` watching that attribute would write it
in clear, readable by anyone holding `activity.view` — a *weaker* permission than the
one guarding the field.

The obvious fix is a list of secret field names in the audit code. That list is correct
the day it is written and silently wrong afterwards, because the next secret field is
added by someone thinking about encryption, not about a log viewer in another module.

So the list is **derived from the model**, from the two declarations that already exist
wherever a secret does — `$hidden`, and any attribute cast to `encrypted*`. A new secret
is protected on the day it is added, by the same declaration that protects it
everywhere else. Over-masking is the safe direction: a masked value in an audit row
costs a support question, an exposed one costs a customer's phone.

Two findings came out of implementing it:

- **spatie v5 writes the model diff to `attribute_changes`, not `properties`.** Guarding
  `properties` alone — the obvious reading, and what the first implementation did —
  masks nothing at all for audited *models*, which never write that column. Both are
  redacted, on the way in, so the clear value is never stored.
- **`tracking_token` and `approval_token` on `RepairTicket` were not declared sensitive
  anywhere.** Both are bearer credentials. Nothing was leaking them, because every
  controller hand-maps its ticket payload rather than serialising the model; they are in
  `$hidden` now, which closes the gap before the first place that does not.

## Decision 3 — The isolation policy is spelled as an OR, and the query names its tenant

This is the expensive finding, and only measurement produced it.

`activity_log` carries a **null-tolerant** RLS policy, because the table holds central
rows (a platform admin acting on a shop) beside tenant ones. It was written the obvious
way:

```sql
tenant_id IS NOT DISTINCT FROM current_setting('app.tenant_id')::bigint
```

**`IS NOT DISTINCT FROM` cannot use a btree index.** An RLS predicate is ANDed into every
query against the table, so the table had no usable index at all — the
`(tenant_id, created_at)` index shipped with the column, and both indexes added for this
viewer, were dead the moment they were created. Nothing errored. The audit log simply
scanned the entire platform to answer a question about one shop, a little slower with
every shop added.

Two changes, both measured on a seeded 1.8M rows — fifty shops, a year of history:

1. **`EnablesRowLevelSecurity` now emits `(tenant_id = current OR (tenant_id IS NULL AND
   current IS NULL))`** for the null-tolerant case. Identical semantics; the equality
   branch is indexable. `activity_log` is the only table that uses this form.
2. **`Activity` carries a global scope** that adds `tenant_id = <current>` (or
   `whereNull` centrally), the way `BelongsToTenant` does for every other model. RLS
   remains the security boundary — the scope exists so the *planner* has a plain
   equality, because it cannot fold `current_setting()` at plan time.

| filter | before | after |
|---|---|---|
| latest 50, no filter | Parallel Seq Scan, 1.8M rows, 55.8 ms | Bitmap over one shop, 93 ms |
| **one record («تاریخچه»)** | Parallel Seq Scan | **Index Scan Backward, 0.074 ms** |
| one actor | Parallel Seq Scan | Bitmap, 25 ms |
| free text | Parallel Seq Scan | Bitmap, 90 ms |

The structural win is not the millisecond counts. It is that **every query used to cost
the whole platform and now costs one shop's slice** — the numbers above stop growing
when a fifty-first shop signs up.

### Null-tolerance is kept — decided, not deferred

The unfiltered view still gets a `BitmapOr` where an `Index Scan Backward` would take
0.23 ms. The plan exists and the planner mis-costs it: it cannot estimate the
selectivity of the `current_setting()` branch, so it will not commit to an ordered scan.
The only reliable cure is a policy with **no OR at all**, which means `tenant_id` may
never be NULL — which means a platform action on a shop would have to be recorded
somewhere the shop cannot see.

**That trade is declined.** A platform staff member signing in as a shop's owner is
precisely the event that shop has the strongest interest in reading, and
`ImpersonationService::record()` already writes it inside `runFor($tenant)` — with a
comment, since Phase 2.4, saying that logging it centrally "would make it invisible to
the only party with a real interest in reading it". Transparency about what we do to a
customer's account is worth more than 0.074ms.

Because that is a decision rather than an implementation detail, it is asserted:
`ActivityLogViewerTest` requires an impersonation entry to be visible to the tenant's own
Owner in their own viewer. Moving that write outside `runFor` would make the row central
— still recorded, still correct, and invisible to the only party who needs it — and it
would pass every other test in the file. Recorded in
[ADR 0002's third amendment](0002-single-db-tenancy-rls.md), where the policy lives.

What remains of the per-shop cost is bounded by one shop's own history, which is what
**retention** bounds. `config('activitylog.clean_after_days')` is 365 and
`activitylog:clean` is **not scheduled** — noted as a post-launch item in the roadmap
rather than built, because choosing how long a shop's audit trail must survive is a
legal and commercial question, not an engineering one.

`properties` and `attribute_changes` are `json`, not `jsonb`. Nothing queries inside
them today; converting is a rewrite of the whole table and belongs with the retention
work if it is ever needed.

## Consequences

- Adding an audited subject is two lines beside the model that owns it: `use Auditable`,
  and one `AuditSubjects::register()` in the module's service provider. The registry
  knows no modules, so `App\Support` never learns what a product is (ADR 0003).
- The filter dropdown cannot drift from what is audited — both come from the same
  registration.
- A module that adds a secret field gets it masked without touching audit code.
- The audit trail is read-only as a tested property, not a convention:
  `ActivityLogRoutesTest` fails if any route reaches the controller with a mutating
  verb, or if the policy grows an ability beyond `viewAny`.
