# ADR 0015 — Observability is a disclosure decision, and it is made here

- **Status:** **Accepted** — directed capture, 2026-08-20
- **Date:** 2026-08-20
- **Deciders:** Project owner + lead engineer
- **Approved by:** Session directive, 2026-08-20: “*proceed straight into 11.3 ops … Sentry
  wiring + health endpoint, Horizon production config — all parameterized*”. The
  parameterisation was the instruction; **what those three tools are allowed to see** is
  the decision that had to be made to carry it out, and this is that decision.
- **Lineage:** [ADR 0002](0002-single-db-tenancy-rls.md) and its amendments (RLS is the
  tenancy guarantee), [ADR 0012](0012-tenant-keyed-caches.md) (a memo is a read that never
  reaches the database). This is the fourth of the same family: **an error report, a health
  body and a queue dashboard are all reads that never reach Postgres either.**

## Context

Phase 11.3 adds three things a platform cannot launch without: a crash reporter, a health
endpoint, and a queue dashboard. Each is standard, each has a five-minute install, and
each — installed the standard way — is a larger tenancy hole than anything RLS protects
against.

The pattern is the same in all three, and it is why they share one ADR rather than three:

| tool | what it is for | what it reads |
|---|---|---|
| Sentry | why a request failed | the request body, the user, the SQL, the breadcrumbs |
| `/health` | whether the box can serve | the connection strings that failed, by name |
| Horizon | whether the queue is moving | **serialised job payloads, from every tenant, on one screen** |

None of that is a database row that a policy can scope. RLS is the guarantee this product
sells, and RLS reaches none of it. The single-shared-schema design (ADR 0002) means the
platform holds fifty shops' customers in one place; these three tools are the three
straightest paths out of it, and two of them lead to a third party.

Horizon is the sharpest case and the one that does not look sharp. `SendSmsJob` carries a
customer's phone number and the text of the message. `SubmitInvoiceJob` carries an
invoice. Those payloads sit in one Redis and render on one page. A shop owner who reached
that page would read the other forty-nine shops' customers — through a screen that looks
like a queue monitor somebody remembered to install.

The obvious install of each is the wrong one:

- Sentry's `send_default_pii` is the switch every guide tells you to turn on, because it
  genuinely does help debugging.
- A health endpoint that returns its diagnostics is more useful than one that does not.
- Horizon's published gate compares an email against a list — resolved from the *default*
  guard, which on a tenant subdomain is a shop's user.

## Decision

**Observability sees the platform's shape and never the tenants' data.**

Concretely, and checkable against code:

1. **Sentry sends no personal data and no query bindings.** `send_default_pii = false`,
   `breadcrumbs.sql_bindings = false`, `tracing.sql_bindings = false`. All three are
   **hardcoded in `config/sentry.php`, not env-driven** — an environment variable is an
   invitation to flip one on mid-incident, which is exactly when the temptation is
   strongest and the judgement worst.

2. **Request bodies are scrubbed through one list, shared with the session door.**
   `App\Support\SensitiveInput` is read by both `dontFlash()` (which keeps a repair
   passcode out of `sessions.payload`) and `ScrubSensitiveData` (which keeps it out of a
   crash report). A key added for either closes both. The crash-report door additionally
   masks by *substring* — over-masking a stack trace costs nothing, while over-dropping a
   flashed field costs an operator retyping it at a counter with a customer waiting.

3. **Events are tagged with the tenant's identity, never its data.** `tenant_id` and the
   shop's slug, which is the subdomain its own customers already type. That answers
   *which shop?* — the question an incident actually asks — without carrying anyone's
   personal data to answer it. A request with no tenant is tagged `platform` explicitly,
   because an absent tag is indistinguishable from a scrubber that broke.

4. **`/health` grades its checks, and the grading is the feature.** Database, cache and
   pending migrations are *critical* and return 503. A queue backlog is *reportable* and
   returns 200. The detailed body requires `X-Health-Secret`; with none configured, nobody
   receives it.

5. **Horizon's gate names the `platform` guard.** Not a role, not an email list, not
   "authenticated" — the separate guard and separate table that exist so this question has
   a one-word answer. `is_active` is re-checked per request. `HorizonAccessTest` asserts a
   shop **Owner**, the most privileged tenant role, receives 403.

## Alternatives considered

**Turn Sentry on the normal way and rely on access control at Sentry.**
Rejected. It moves the boundary to a system with different administrators, a different
retention policy and a different breach surface, and it makes the blast radius of a
Sentry credential the whole platform's customer data. The data that never leaves cannot
leak.

**Make the three safety settings environment variables with safe defaults.**
Rejected, and this was the closest call — it costs a real debugging affordance. But the
scenario is specific: production is broken, somebody wants the request body, and the
variable is one line away. A setting whose *only* correct value is the default is better
expressed as the default and nothing else. Adding local variables to a Sentry event, per
call site, remains available and is deliberate rather than blanket.

**Grade every health check critical, for completeness.**
Rejected, and it is the failure this ADR most wants to prevent recurring. A backlogged
SMS queue does not stop a shop selling a phone. Grading it critical means the load
balancer removes a healthy web tier because a third-party gateway is slow, converting a
delayed text message into a shop that cannot take payment — a monitoring system causing
the outage it was installed to catch.

**Return full health diagnostics publicly; it is only infrastructure detail.**
Rejected. `SQLSTATE[08006] … could not connect to server: db-internal:5432` names hosts,
ports and drivers, and it is most readable precisely when something is already broken and
somebody is already looking. The uptime probe needs a status code, not a paragraph.

**Show details when no secret is configured, so the endpoint is useful out of the box.**
Rejected: it fails *open*. A box would publish its topology because of a variable somebody
did not know to set — the failure caused by an omission rather than a decision, which is
the kind nobody audits.

**Put Horizon behind the Filament panel's existing auth.**
Rejected as insufficiently explicit. It would work today and silently stop working the day
the panel's middleware is reorganised. The gate asks the guard directly, and a test
asserts the denial, so the property cannot invert without something going red.

## Consequences

**Positive**

- The three most-likely disclosure paths out of a fifty-tenant platform are closed by
  configuration that is committed, commented and tested, rather than by nobody having
  thought to open them.
- One sensitive-key list, two doors. The next secret field is protected in both places by
  the same edit, and `CrashReportScrubbingTest` iterates the list rather than spot-checking
  it, so a key added without a matching mask fails.
- An incident can still be attributed to a shop in one search, which is what the reporter
  was installed for.
- The critical/reportable split gives the deploy script and the load balancer a signal they
  can act on without the queue's health being able to take the site down.

**Negative, and accepted**

- **Debugging a production 500 is harder.** No request body beyond the scrubbed shape, no
  bindings on the slow query, no operator identity. Reconstructing a failure takes the
  stack trace, the tenant tag and the audit log instead of one screen. This is the real
  cost and it will be felt.
- **Over-masking.** The substring rule masks keys that were never secret. Accepted for the
  same reason the audit redactor accepts it: a masked value in a crash report costs a
  support question, an exposed one costs a customer's phone.
- **A second health concept to keep straight.** `/up` and `/health` answer different
  questions and somebody will point a probe at the wrong one. Documented in
  `docs/deploy.md §5`; the cost of merging them is worse, because the cheap liveness check
  would then cost two round trips on every probe.
- **The grading is a judgement that can age.** If a queue backlog ever *does* mean the web
  tier cannot serve, `HealthCheck::CRITICAL` has to change — deliberately, with this ADR in
  hand, rather than by somebody adding `queue` to the list for tidiness. The test that
  asserts `queue` is not critical exists to force that conversation.
