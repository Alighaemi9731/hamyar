# Architecture Decision Records

One file per decision that would be expensive to reverse or that a future contributor
would otherwise be tempted to undo. Each records the context, what was decided, what
was rejected and why, and the consequences we accepted.

A decision marked **Binding** cannot be changed without a new ADR that supersedes it.

## Status must point at evidence

**An ADR marked Accepted names where a human approved it** — a cleared decision gate in
`../ROADMAP.md`, a dated `../PROGRESS.md` entry, or a rule the project owner wrote into
`CLAUDE.md`. Every file carries an **Approved by** line saying which.

This rule exists because it was broken. ADR 0009 was written marked *"Accepted at
DECISION GATE 3"*, and described an alternative as *"rejected at the gate"*, before that
gate had been held — the roadmap still carried it as open. It was corrected to Proposed,
taken to the gate for real, and approved unchanged. An audit of all nine followed and is
recorded in `../PROGRESS.md` (2026-08-12).

An ADR's entire value is that a later reader can trust what it says was agreed. A status
nobody can trace is worse than no ADR, because it manufactures consent that was never
given — and the next person to disagree with the decision is arguing with a ghost.

| # | Decision | Status | Approved where |
|---|---|---|---|
| [0001](0001-stack.md) | Technology stack — Laravel 12, PostgreSQL 16, Inertia + React, Filament | Accepted | CLAUDE.md stack section (owner-authored) |
| [0002](0002-single-db-tenancy-rls.md) | Single database, shared schema, Postgres RLS, non-superuser app role | Accepted | CLAUDE.md golden rule 1; re-confirmed at Gate 1 |
| [0003](0003-modular-monolith.md) | Modular monolith; ledgers instead of stored totals | Accepted | CLAUDE.md golden rules 3 and 6 (owner-authored) |
| [0004](0004-postgres-only-tests.md) | The test suite runs only against real PostgreSQL | Accepted | PROGRESS 2026-08-07 · ROADMAP 0.2 |
| [0005](0005-rtl-direction-class-gate.md) | RTL enforced by a build gate matching Tailwind value syntax | Accepted | PROGRESS 2026-08-07 · ROADMAP 0.2 |
| [0006](0006-proration.md) | Subscription proration formula | Accepted | **Gate 2**, cleared 2026-08-08 |
| [0007](0007-tenant-session-variable.md) | `set_config(..., false)` rather than `SET LOCAL` for `app.tenant_id` | Accepted | **Gate 1**, cleared 2026-08-07 |
| [0008](0008-visual-language.md) | Visual language: calm neutral ground, one blue, pill actions | Accepted | Owner *request* + delivery, PROGRESS 2026-08-07 — the weakest provenance of the nine; see the file |
| [0009](0009-invoice-rounding.md) | Invoice rounding: whole toman, floor VAT, round the total once | Accepted | **Gate 3**, cleared 2026-08-12 |
| [0010](0010-job-context-teardown.md) | Tenant-context teardown is conditional on how a job entered | Accepted | Directed capture, autonomous-run authorization 2026-08-14 |
| [0011](0011-moadian-adapter-without-a-provider.md) | Moadian ships as an adapter with no real provider, flag off | Accepted | **Gate 4 (part 2)**, cleared 2026-08-16 |
| [0012](0012-tenant-keyed-caches.md) | A cache outliving a request leads its key with the tenant id | Accepted | Directed capture 2026-08-18 · lineage of 0002 |
| [0013](0013-flat-product-import.md) | Products import is flat: one row = one product + one variant, grouping opt-in | Accepted | **Checkpoint 2**, cleared 2026-08-18 |
| [0014](0014-audit-surface-and-log-isolation.md) | What the audit log records, and how its own rows stay tenant-isolated | Accepted | 11c delivery, PROGRESS 2026-08-18 |
| [0015](0015-observability-without-disclosure.md) | Observability sees the platform's shape, never the tenants' data | Accepted | Directed capture 2026-08-20 · lineage of 0002 |
| [0016](0016-landing-direction.md) | Landing page direction | Accepted | **Gate 5**, second pass, 2026-08-20 |
| [0017](0017-single-host-app.md) | One host for the application; tenant from the session, not the hostname | Accepted | Owner decision 2026-08-21 |
| [0018](0018-metered-plans.md) | Metered plans: every module open, quantity limits per window, a three-rung ladder |
| [0019](0019-ui-redesign-directions.md) | The UI redesign's directions: page families, screen/document split, the `xl` split, the 40px floor, RTL from the root, evidence as a number | **Proposed** | Bound for **Gate 6** (ROADMAP Phase 12) |

## Writing a new one

Copy the shape of an existing file:

1. **Context** — what forced a decision. Include the constraint that made the obvious
   choice wrong.
2. **Decision** — what we do, concretely enough to check code against.
3. **Alternatives considered** — each with the actual reason it lost. An ADR with no
   rejected alternatives is a note, not a decision record.
4. **Consequences** — positive *and* negative. Name the costs we accepted.
5. **Approved by** — the gate, PROGRESS entry or CLAUDE.md rule where a human agreed.
   A new ADR starts as **Proposed** with the gate it is bound for named, exactly as 0006
   did while it waited for Gate 2. It does not become Accepted because it was written.

Reference ADRs from code comments where the reasoning is not local — for example the
Postgres bootstrap script and `phpunit.xml` both point at 0002 and 0004, because on
their own they look like arbitrary configuration.