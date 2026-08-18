# Security

**Phase 11.1** · Target: **OWASP ASVS 4.0, Level 1**, the baseline for an application
handling other people's commercial data.

This is an audit, not an aspiration. Every line below is either **verified** — with the
file or test that proves it, so a reader can check rather than believe — or named as a
**gap** with what closing it costs. A checklist whose items are ticked because they
sound true is worse than no checklist: it converts an unknown into a false known.

Where a control is deliberately *not* implemented, the reason is written down. "Accepted"
means somebody decided; it does not mean nobody noticed.

---

## The threat model, in one paragraph

Fifty shops share one database and one application. The three things worth protecting, in
order: **one shop's data from another shop** (a leak here is the product's core promise
broken, and it is not recoverable by apology); **a customer's device passcode and
identity documents**, because a leaked unlock code is a stolen phone; and **money
integrity** — prices, ledgers, cheques — where a silent corruption is discovered months
later by an accountant. The attacker we design against is not a nation state. It is a
competitor who signs up for a trial, a curious shop owner editing a URL, a stolen
laptop with a live session, and an npm package that turned malicious.

---

## V1 · Architecture

| # | Control | Status |
|---|---|---|
| 1.1 | Tenant isolation is enforced by the **database**, not the application | **Verified** — Postgres RLS with `FORCE`, `USING` **and** `WITH CHECK`, on every tenant table ([ADR 0002](adr/0002-single-db-tenancy-rls.md)) |
| 1.2 | The isolation layer fails **closed** | **Verified** — an unset `app.tenant_id` reads as NULL, and `tenant_id = NULL` is never true, so the failure mode is "no rows" rather than "all rows" |
| 1.3 | Isolation is enforced mechanically, not by review | **Verified** — `php artisan tenancy:check` fails CI when a tenant table lacks RLS, a model lacks the trait, or a unique index omits `tenant_id` |
| 1.4 | Cross-tenant reads are explicit and narrow | **Verified** — only `TenantContext::runAsPlatform()`, only billing tables, asserted by `PlatformBillingIsolationTest` |
| 1.5 | Module boundaries | **Verified** — `tests/Arch/ModuleBoundariesTest.php` |

## V2 · Authentication

| # | Control | Status |
|---|---|---|
| 2.1 | Password minimum length and breach check | **Verified** — `Password::min(8)->uncompromised()` in `AppServiceProvider::configurePasswords()`. Disabled under test only, because a test must not call an external service ([testing policy](testing.md)) |
| 2.2 | Credential-stuffing throttle on login | **Verified** — `LoginRequest` throttles per credential **and** IP, not per route, so rotating usernames from one address does not reset the counter |
| 2.3 | Second factor available | **Verified** — TOTP with confirmation and recovery codes; challenge throttled `10,1` |
| 2.4 | Password reset is rate-limited and single-use | **Verified** — `throttle:6,1` on request and update |
| 2.5 | Session belongs to the shop it is used on | **Verified** — `EnsureUserBelongsToTenant`, applied after `auth`. A session from shop A presented on shop B's hostname is rejected |
| 2.6 | Staff and platform staff are separate identities | **Verified** — `User` and `PlatformUser` are different models on different guards; the panel 404s on tenant hostnames |

## V3 · Session management

| # | Control | Status |
|---|---|---|
| 3.1 | `HttpOnly` cookies | **Verified** — `config/session.php`, default `true` |
| 3.2 | `SameSite` | **Verified** — `lax` |
| 3.3 | `Secure` cookies in production | **Deployment-dependent** — `SESSION_SECURE_COOKIE` must be `true` in the production env file. Listed in the [deploy runbook](deploy.md) go-live checklist; it is an env value, so no code change can guarantee it |
| 3.4 | Session invalidated and regenerated on login/logout | **Verified** — Laravel default, exercised by `AuthenticatedNavigationTest` |
| 3.5 | Sessions are listable and revocable by the owner | **Verified** — `/settings/sessions` |

## V4 · Access control

| # | Control | Status |
|---|---|---|
| 4.1 | Every resource has a policy | **Verified** — `Gate::policy()` per module provider; permission names are `module.action` |
| 4.2 | Plan gating is enforced server-side | **Verified** — `EnsureModuleEnabled` on the route. Hiding nav items is convenience only (golden rule 7) |
| 4.3 | Cross-tenant access returns 404/403 | **Verified** — an isolation test per tenant-scoped endpoint (golden rule 8); the suite runs as its own CI step |
| 4.4 | Privilege escalation via self-assignment | **Verified** — a user cannot assign roles to themselves (`UserManagementTest`) |
| 4.5 | The audit log is read-only | **Verified** — `ActivityLogRoutesTest` fails if any route reaches it with a mutating verb |

## V5 · Validation, sanitisation and encoding

| # | Control | Status |
|---|---|---|
| 5.1 | Validation is a FormRequest, never inline | **Verified** — convention, enforced by review; every endpoint has one |
| 5.2 | Output encoding | **Verified, with three deliberate exceptions** — React escapes by default. Three screens inject a server-generated SVG (label barcodes, repair-receipt QR, invoice QR) because an `<img>` cannot be printed at an exact millimetre size. `tests/Feature/InlineSvgIsInertTest.php` parses the output of both generators against hostile payloads and asserts zero script nodes and zero event-handler attributes. See below |
| 5.3 | SQL injection | **Verified** — Eloquent and the query builder throughout; the only interpolated SQL is DDL in migrations, guarded by `EnablesRowLevelSecurity::guardIdentifier()` |
| 5.4 | Uploaded files are validated by type and size | **Verified** — FormRequest rules on every upload endpoint |
| 5.5 | Uploaded files are served from a signed, time-limited URL | **Verified** — `AttachmentStore::temporaryUrl()`, 15 minutes. Never a public bucket path: these are photographs of other people's property |

## V7 · Error handling and logging

| # | Control | Status |
|---|---|---|
| 7.1 | Secrets never reach a log | **Verified mechanically** — `tests/Feature/SecretColumnsTest.php` asserts every `encrypted` attribute is also `$hidden`, which is what keeps it out of `toArray()`, a JSON response and a `Log::info($model)` |
| 7.2 | Secrets never reach the audit trail | **Verified** — redaction on write from a list *derived* from each model's `$hidden` and encrypted casts ([ADR 0014](adr/0014-audit-surface-and-log-isolation.md)), asserted against the **rendered** page |
| 7.3 | Sensitive actions are audited | **Verified** — passcode reveal and impersonation write an entry *before* the action, so a failure to record costs the actor the secret rather than costing the shop the record |
| 7.4 | Stack traces are not shown in production | **Deployment-dependent** — `APP_DEBUG=false`. Go-live checklist |

## V8 · Data protection

**The encrypted-columns inventory is derived, not listed here.** A list in a document is
correct the day it is written; `tests/Feature/SecretColumnsTest.php` asks the models, and
fails the build when an encrypted attribute is not also hidden.

At the time of writing it finds four: `users.two_factor_secret` and
`two_factor_recovery_codes`, `platform_users` the same pair,
`repair_tickets.device_passcode`, and `moadian_settings.private_key`.

Two further values are **bearer credentials without being encrypted**, and are `$hidden`
for the same reason: `repair_tickets.tracking_token` and `approval_token`. They are
random 48-character tokens rather than secrets derived from user data, so the protection
they need is non-disclosure, not encryption at rest.

## V9 · Communications

| # | Control | Status |
|---|---|---|
| 9.1 | HTTPS forced outside development | **Verified** — `URL::forceScheme('https')` in production and staging |
| 9.2 | HSTS | **Verified** — one year, `includeSubDomains`, sent only on an already-secure request in production/staging. Deliberately never in dev: it would pin `*.localhost` in a developer's browser for a year |

## V12 · Files and resources

Covered under V5.4–5.5.

## V13 · API and web service

| # | Control | Status |
|---|---|---|
| 13.1 | CSRF | **Verified** — Laravel's `web` group; Inertia sends the token from the `csrf-token` meta tag |
| 13.2 | Rate limits on unauthenticated surfaces | **Verified** — login (per credential + IP), password reset `6,1`, 2FA challenge `10,1`, invitation accept `10,1`, public repair tracking `30,1`, price list `60,1`, price-list unlock `10,1`, public invoice `60,1`, impersonation start `10,1` |
| 13.3 | Signed URLs | **Audited** — see below |

### Signed-URL audit

| Link | Signature | Expiry | Extra control |
|---|---|---|---|
| Impersonation start | `temporarySignedRoute` | short TTL | `signed` middleware, `throttle:10,1`, platform guard, audit entry written first |
| Public invoice (receipt QR) | `signedRoute` | **none, deliberately** | `signed`, `throttle:60,1` |
| Attachment fetch | storage signature | 15 min | — |
| Repair tracking | *not signed* — a 48-char random token | none | `throttle:30,1`, route constrained to `[A-Za-z0-9]{48}` |
| Reseller price list | *not signed* — random token | none | `throttle:60,1`, optional password, `throttle:10,1` on unlock |

**The permanent invoice signature is a decision, not an oversight.** The receipt is
printed on paper that outlives any expiry, a customer scanning a dead QR rings the shop,
and anyone holding the paper already has everything the page shows. Recorded in
`PublicInvoiceLink`'s own docblock.

**The two token links are not signed URLs and should not be.** A signature binds a URL to
an application key; these are capabilities printed on paper and texted to customers, and
they must survive a key rotation. The control is token entropy plus rate limiting.

## V14 · Configuration

| # | Control | Status |
|---|---|---|
| 14.1 | Security headers | **Verified** — `App\Http\Middleware\SecurityHeaders`, asserted by `tests/Feature/SecurityHeadersTest.php`. Registered **globally**, not in the `web` group: group middleware runs only after a route matches, so a 404 came back unprotected |
| 14.2 | Content-Security-Policy | **Verified** — nonce-based `script-src`, no `'unsafe-inline'` for scripts. See the relaxation below |
| 14.3 | Dependency vulnerability audit in CI | **Verified** — `composer audit` in the Style job, `npm audit --omit=dev --audit-level=high` in the assets job |
| 14.4 | Secrets are not in the repository | **Verified** — `.env*` is gitignored except `.env.example`, which carries placeholders only |

### The three `dangerouslySetInnerHTML` sites

Worth naming, because "React escapes by default" stops being an answer the moment
something opts out.

The barcode's input is a variant's `barcode` or `sku` — **operator-supplied, and
settable in bulk through the products import**, so the shortest path to that string is a
spreadsheet emailed to a shop. Code 128 encodes all printable ASCII, `<` and `>`
included.

Picqer escapes it: the code lands in a `<desc>` element as entities and the bars carry no
text. Verified by parsing the output rather than by reading the library — the first
attempt at this check searched the SVG for the substring `script`, matched
`&lt;script&gt;` inside the escaped description, and reported a vulnerability that was
never there. **A test that looks for dangerous-looking text finds escaped text.** The
question is whether a parser sees a script node, so the test asks a parser.

The CSP is the second layer under it: an injected `<script>` has no nonce, and an
`onerror` attribute is inline script. Neither would run even if a generator regressed.

### The one CSP relaxation, and why

`style-src` carries `'unsafe-inline'`. Seven components set a style **attribute** for a
genuinely computed value — bar-chart column heights, print-layout sheet width, the label
sheet's millimetre dimensions — and `style-src` governs those. Without the relaxation
every chart flattens and every printed label sheet is mis-sized, which reads as a
rendering bug rather than a policy.

Accepted because the risk is narrow: injected CSS can restyle a page and cannot execute.
The directive that stops code keeps its nonce, and a test asserts `script-src` never
gains `'unsafe-inline'` — a policy carrying both is one where the nonce is decoration,
because browsers ignore a nonce once `'unsafe-inline'` is present.

---

## Open items

| Item | Why it is still open |
|---|---|
| `shadcn` is in `dependencies`, not `devDependencies` | It is a scaffolding CLI, not shipped code, and it is what pulls `postcss` → `nanoid` into the **production** tree — which is how 11.1's first `npm audit` found a high-severity advisory in a package no shop's browser ever loads. Moving it is a one-line change to `package.json` and a dependency change, so it is proposed rather than taken |
| Penetration test | Not scheduled. ASVS L1 is a floor; it is not a substitute for somebody trying |
| `SESSION_SECURE_COOKIE`, `APP_DEBUG` | Env values. Verified at go-live, not by code — see [deploy.md](deploy.md) |

## What this document is not

It is not evidence of compliance with anything. It is a record of what was checked, by
whom, and when — so the next person can tell the difference between a control that was
verified and one that was assumed.

Last audited: **1405/05/27 (2026-08-18)**, Phase 11.1.
