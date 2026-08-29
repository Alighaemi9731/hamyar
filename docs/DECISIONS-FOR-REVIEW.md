# Decisions taken during the autonomous run

Batched for review at DECISION GATE 4, per the authorization of 2026-08-14. Each entry is a
call I made rather than stopping for: what was decided, why, and what it would cost to
reverse.

Nothing here is blocking or irreversible — anything that was would have stopped the run.

---

## Phase 8 — Messaging

**Four roadmap items deferred rather than built.** All are on the roadmap unticked with the
phase named beside them; none blocks anything.

- **sms.ir stub** — a second driver written against no account is a guess at an API. The
  abstraction is already proven by two implementations (fake + Kavenegar). Cost to add when
  a shop needs it: one class.
- **Price tiers** — Phase 11, with billing. One platform rate today.
- **Per-message delivery polling** — needs a scheduled poller and a real gateway account to
  poll against. Provider references are stored, so the data is ready.
- **Notification bell** — Phase 9 builds the dashboard it belongs on. The message log ships
  in Phase 8 and answers the same question.

**Quiet hours apply to swept automations and not to event-driven ones.** A repair marked
ready at 9pm texts immediately, because the customer is waiting for exactly that message. A
birthday greeting or a due-date reminder waits for morning. Reversible: one boolean on the
automation key.

**The wallet may go slightly negative under extreme concurrency.** `charge()` reads the
balance inside a transaction, which serialises but does not lock a balance row — there is no
balance row. Two messages queued in the same instant against a wallet holding enough for one
could both proceed. Refusing a customer's «آماده است» because two messages raced is worse
than a shop being 3,000 rial down. Reversible: an advisory lock keyed on the tenant.

## Phase 9 — Reporting

**`ReportPeriod` swaps a backwards date range rather than rejecting it.** Somebody typing
1405/05/31 into the "from" box mistyped; they did not ask for nothing. Reversible: throw
instead.

**Report figures compose the existing engines rather than re-deriving.** `SalesReports`
calls `ProfitEngine` for totals and reads the same columns for breakdowns, and its summary
keys mirror the engine's names exactly (`profit`, not `margin`). Renaming would give the
shop two names for one figure and make a mismatch between two screens impossible to spot.

---

## Phase 12 — Metered plans (autonomous run, 2026-08-30)

Authorized by the owner on 2026-08-30: «خودت کامل پیش برو دیگه از من چیزی نپرس و هر چی تو
برنامه داری تا اخرین موردش رو ایمپلیمنت کن». The sixteen gate items were answered first
(ROADMAP, Gate 6 block); everything here is a call made *inside* those answers.

**Real multi-process concurrency is not tested in CI, and the reason is not laziness.** The
whole design rests on `INSERT … ON CONFLICT DO UPDATE … WHERE` being atomic. A fork harness
was written — twenty children at the last unit, one PDO connection each, counting winners —
and then removed. Forking inside PHPUnit is fragile in ways unrelated to the code under
test (inherited handles, output buffers, shutdown handlers running twice), and a
concurrency test that hangs the build once a fortnight teaches everyone to re-run CI
instead of reading it. What replaced it asserts the two properties that make the race safe
and are deterministic: **one statement per spend** (a refactor into read-decide-write fails
the test, and that refactor *is* the double-spend bug) and **the cap evaluated against
committed state** (a spend decided from a stale read is refused). Postgres's own guarantee
— the loser of an `ON CONFLICT` race waits on the winner's tuple and re-evaluates the
`DO UPDATE … WHERE` under READ COMMITTED — is documented behaviour we rely on rather than
re-prove. Reversible: `AtomicityTest`'s docblock says exactly what a fork harness would add.

**`bin/check-quota-scoping` skips the test suite.** The three quota tables are
platform-owned, so nothing adds a tenant filter and every production query must carry its
own. Tests query them unscoped *on purpose* — that is how an isolation test proves RLS is
the thing doing the work. Applying the gate to the suite would put an escape-hatch comment
on every test that does its job. Reversible: delete four lines in the gate.

**`SubscriptionResolver::forTenantId()` became public.** `LimitResolver` needs the same
memoised lookup and holds an id rather than a model — it runs on the write path, where
fetching a `Tenant` to pass in would be a query to avoid a query. Reversible: make it
private again and pay the extra query.

**A paid invoice for a tenant with no subscription now creates one** (12.1). Not reachable
through the normal signup flow, which provisions one; fixed anyway, because "we took the
money and the shop got nothing" is not a failure mode to leave to another path being
perfect. Reversible: delete `startSubscriptionFor()`.

**`PlanResource` is pinned to `id`** while `Plan::getRouteKeyName()` is `code`. The
shop-facing route needed the code; the panel's URLs were never part of that decision, and
letting a model-level change rewrite every `/admin/plans/{id}/edit` link is a blast radius
nobody asked for. Reversible: delete one property.

**`MetricKind` was designed and then not built.** With `Window::Day` gone at the gate,
`Month` ⇔ counted and `Total` ⇔ computed exactly; a second enum could only agree with the
window or disagree with it, and the second is the bug. `Metric`'s constructor enforces the
pairing instead. Recorded in ADR 0018 rather than left as drift.
