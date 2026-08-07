# Architecture Decision Records

One file per decision that would be expensive to reverse or that a future contributor
would otherwise be tempted to undo. Each records the context, what was decided, what
was rejected and why, and the consequences we accepted.

A decision marked **Binding** cannot be changed without a new ADR that supersedes it.

| # | Decision | Status |
|---|---|---|
| [0001](0001-stack.md) | Technology stack — Laravel 12, PostgreSQL 16, Inertia + React, Filament | Accepted |
| [0002](0002-single-db-tenancy-rls.md) | Single database, shared schema, Postgres RLS, non-superuser app role | Accepted |
| [0003](0003-modular-monolith.md) | Modular monolith; ledgers instead of stored totals | Accepted |
| [0004](0004-postgres-only-tests.md) | The test suite runs only against real PostgreSQL | Accepted |
| [0005](0005-rtl-direction-class-gate.md) | RTL enforced by a build gate matching Tailwind value syntax | Accepted |
| 0006 | Subscription proration formula | *Pending — Phase 2, Decision Gate 2* |
| [0007](0007-tenant-session-variable.md) | `set_config(..., false)` rather than `SET LOCAL` for `app.tenant_id` | Accepted |

## Writing a new one

Copy the shape of an existing file:

1. **Context** — what forced a decision. Include the constraint that made the obvious
   choice wrong.
2. **Decision** — what we do, concretely enough to check code against.
3. **Alternatives considered** — each with the actual reason it lost. An ADR with no
   rejected alternatives is a note, not a decision record.
4. **Consequences** — positive *and* negative. Name the costs we accepted.

Reference ADRs from code comments where the reasoning is not local — for example the
Postgres bootstrap script and `phpunit.xml` both point at 0002 and 0004, because on
their own they look like arbitrary configuration.
