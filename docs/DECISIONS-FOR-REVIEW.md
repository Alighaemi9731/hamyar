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
