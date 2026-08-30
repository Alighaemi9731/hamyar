# Changelog

Every release, what changed, and **why** — the reason rather than the diff, because the
diff is already in Git and it is the one thing that cannot explain itself.

Versions follow `docs/VERSIONING.md`. A release is cut with `bin/release` and is not a
release until `bin/smoke` has confirmed, from outside the box, that the site is serving
it. Tags and published archives: <https://github.com/Alighaemi9731/hamyar/releases>.

## 0.16.0 - 2026-08-30

**The bundle tables are gone, and the meter now has tests where a shopkeeper meets it.**
Phase 12.15 as scheduled, plus the phase's Definition-of-Done item that nobody had walked —
which is what found the two things below.

**`plan_module`, `subscription_addons`, `modules.is_addonable` and `modules.addon_price`
dropped**, one release after `0.15.0` stopped reading them. That gap is the blue/green rule
in `docs/VERSIONING.md`, not caution for its own sake: a deploy runs both containers against
one already-migrated database, so a column dropped in the same release that stops writing it
takes the site down for the length of the cutover — and the 500s come from the *old*
container, which reads as a reason to roll back rather than as that release's fault. With
`SubscriptionAddon`, `Subscription::addons()`, `Plan::modules()` and `Module::plans()`.
`laravel/pennant` removed with them; nothing had imported it since Gate 6 turned gating from
availability into quantity.

`ModuleResource` was on the list to delete and is deliberately kept. Its add-on fields went
in `0.15.0`; what is left is the only screen that flips `modules.is_enabled`, the platform
kill-switch Gate 6 chose to keep. Deleting it would have left a switch only Tinker can reach.

**`QuotaExceeded` no longer extends `RuntimeException`, and that one word had disabled the
block on most of the product.** A dozen controllers wrap their domain call in
`catch (RuntimeException $e)` and turn it into a field-level validation message — the
established way this codebase reports «موجودی کافی نیست» beside the input that caused it.
Every one of those arms swallowed the quota block on its way past. At the till, a shop that
hit its monthly invoice cap got the raw English `Quota exceeded for [sales.invoices]: 300
used of 300, 1 requested.` under the line-items field, and `quota_block` — the payload
carrying the Persian sentence, the reset date and the upgrade button — never reached the
page at all.

Nothing crashed. The refusal was correct, the transaction rolled back, no credit was spent;
only the *telling* was wrong, which is why no existing test saw it. Every quota test
asserted on the counter, and the counter was right. Extending `Exception` puts the block
outside those catch arms by construction, including in controllers nobody has written yet —
the alternative, adding `catch (QuotaExceeded) { throw; }` above a dozen existing arms,
works today and silently stops working at the thirteenth.

POS additionally deletes its draft and re-throws: the block redirects back to the till, and
an operator with a customer waiting presses the button again, so every press that left its
draft behind would park another numberless orphan.

**Six metered features have no way in, and the boxes that said otherwise are corrected.**
Looking for an enforcement site to test found that across 104 write routes nothing creates a
cheque, an expense or income, a recurring template, a rental contract, a campaign, or a
treasury account. Each has its service, its ledger matrix and its tests — a `Cheque` row is
written in nine test files and zero production files — and each is priced on the ladder, so
the free rung advertises «۵۰ ثبت چک» for something a shop cannot do once. Roadmap 7.2, 7.3
and 8.3 are now `[~]` with the reason on the line, and §12.16 names the six missing screens.
Not built here: six create screens is Phase 7/8 product work, not a release about dropping
tables, and it is written down rather than fixed quietly because the dangerous version of
this bug is the one nobody says out loud.

**One `QuotaEnforcementTest` per metered module**, driving the real route at its ceiling:
the write is refused, the session carries the `quota` key, the block payload has the keys
the React component reads, its message is Persian, and no counter row exists at all — which
is a different claim from a row reading zero, and the only one that says the transaction
really rolled back. Plus the exemptions nothing had ever asserted: a repair-delivery invoice
does not spend a sales credit, viewing a report on screen does not spend an export, and
collecting an installment payment is free.

**Docs caught up with Gate 6.** `docs/specs/platform.md` (limits, screens, events,
acceptance), the Gating rule in `docs/specs/README.md`, one acceptance line on each of the
14 metered module specs, `docs/architecture.md` — which still described `SET LOCAL`, the
exact thing golden rule 1 exists to forbid — `docs/VERSIONING.md`, and `docs/testing.md`,
which cited two test helpers that do not exist. `docs/load-testing.md`'s "never against
production" note is rewritten to key on *what the data is worth* rather than on a box's
name, so it cannot expire again the way it did; and the volume seeder now seeds onto the
unlimited plan, since fifty shops writing thousands of invoices is precisely what the meter
exists to refuse.

Not deployed. There is no production server as of 2026-08-29; `bin/release --deploy` and
`bin/smoke` stay suspended until the owner provides the new box.

## 0.15.1 - 2026-08-30

**Every module now spends credits, and four real bugs surfaced doing it.** Phases 12.6
through 12.14: the block experience, every remaining call site, the events, the operator
tooling and the error pages. None of the four were in the quota code; all four were found
because metering made previously-unreachable paths reachable.

**`runAsPlatform()` was not re-entrant.** Its `finally` forced the flag OFF, so a nested
call — an event listener recording something inside a platform-context write — cleared it
for the outer block that was still running. RLS does not error when the flag is missing; it
returns nothing. The symptom was a `findOrFail` on a row that plainly existed, a hundred
lines from the nested call that caused it. The depth is now counted and cleared once, by
the outermost caller — the same discipline `runFor()` has always had.

**`LimitResolver` read `subscriptions` without the platform flag**, so called for an
arbitrary tenant — from a job, from the panel — it saw no subscription, fell through to the
fallback plan, and metered a shop on the top rung at the free rung's credits. Silently.

**`nextPlanFor()` never offered an upgrade to a shop on a private plan.** It walked the
public plans until it met the current one, and a plan with `is_public = false` — a
grandfathered price, a negotiated deal — never appears in that list. The customer most
likely to be on a private plan is the one paying us most.

**Proration could produce a price the product cannot say.** `intdiv` truncated to the
rial, and `Money::toToman()` refuses to round money — it throws. Unreachable while every
upgrade subtracted an unused credit that happened to round the total off; reachable the
moment a plan was free and the amount due WAS the raw portion, at which point the billing
page 500s. Proration now truncates to a whole toman, keeping ADR 0006's direction (the
remainder stays with the customer) and making all three figures on the line add up.

Three more, found while metering: `PartyController@store` wrote a party and its contacts as
two unwrapped statements, so a failure between them left a customer with no phone number;
`FileStore::attach` put the blob on the disk before the row existed, leaving orphaned files
nothing referenced; the POS quote path took its number outside a transaction, one
concurrent pair of requests from two quotes sharing a number.

**Being blocked is an error bag plus a payload the shell renders once**, never a 4xx page —
this application has no Inertia error page, so a 403 from a POST reaches the shopkeeper as
Inertia's English iframe modal. It also now HAS error pages: 403/404/419/500 in Persian and
RTL, which finally makes visible the two Persian sentences `ResolveTenant` has been writing
since Phase 1 and nobody had ever seen.

Deferred with reasons on the roadmap: the quota SMS alerts and the renewal-reminder
listener, because both need a pattern registered with an SMS provider we have no account
for — the same reason Phase 8 deferred `sms.ir`.

**No release** — still no production server.

## 0.15.0 - 2026-08-30

**BREAKING — every module is open to every shop, and the first rung is free.** The plan
model the owner approved at DECISION GATE 6, in code. Prefixed BREAKING because an existing
plan row's *meaning* changes: `plan_module` stops being read, `plan_limits` keys become
metric keys, and a `trialing` subscription is migrated to `active` on the free plan.

**What a plan sells is now quantity.** `basic` is free, permanently, with real monthly
credits — 300 invoices, 200 IMEI units, 200 products, 100 repair tickets — and zero SMS,
because SMS is the one credit that costs us cash per unit and a free rung that handed it out
would be a free SMS service for anyone willing to re-register. `pro` is 5,000 invoices;
`enterprise` is unlimited except the two things that cost per unit whatever we charge (25
seats, 50 GB), which an override lifts for a particular shop.

**`TrialPolicy` is deleted, not inverted.** The free rung replaced the 14-day trial: a shop
evaluates the product by using it, for as long as it likes. That also gives a lapsed paid
subscription somewhere coherent to land instead of a lock-out.

**`EnsureModuleEnabled` survives with a different question.** It used to ask "does this
shop's plan include Repairs?" and 403 if not — correct for a product where a plan bought
modules, and wrong for one where it buys quantity: a shop three days late on payment found
every screen 403ing and concluded the software was broken. It now asks "have WE switched
this module on", reading `modules.is_enabled`, which is what ADR 0011 has needed for Moadian
since it shipped without a provider. What fails closed is the quota layer, which can refuse
a *create* without taking the product away.

**The panel is where limits live.** `PlanForm` is built from the metric registry — one
field per metric, grouped by owning module — so shipping a metered action in Sales makes it
appear on the pricing screen without Platform being touched. Saving bumps every shop on the
plan, so an edit applies on their next request rather than whenever each worker restarts.

**The landing page sells credits.** Pricing rows list monthly quotas with «رایگان» on the
first rung, the add-on shelf is gone with the product it advertised, the trial CTAs became
«رایگان شروع کنید», and Terms §3 explains the credit model and says plainly that a lapsed
subscription drops to the free plan rather than closing the shop.

**No release** — still no production server.

## 0.14.5 - 2026-08-30

**The meter, and the lifecycle it depends on.** `usage_counters`, `tenant_limit_overrides`
and `usage_events` now exist, along with `DatabaseQuotaGuard` and `LimitResolver`. Nothing
calls the guard from a module yet — the call sites land module by module next — but the
machine underneath is complete and tested.

**A spend is one statement.** `INSERT … SELECT … WHERE n <= limit ON CONFLICT (tenant_id,
metric, period_key) DO UPDATE SET used = used + EXCLUDED.used WHERE used + EXCLUDED.used <=
limit RETURNING used`. The read-decide-write shape it replaces is the double-spend bug: at
the last unit of a credit, two requests both read "one left", both decide yes, and the shop
gets two for one — silently, on the busiest day, which is when it happens. Every
placeholder in the bounded statement carries an explicit cast, because Postgres cannot
infer a parameter's type outside a `VALUES` column position and fails at prepare time
without them.

**Three states that had been modelled since Phase 2 with nothing writing them.**
`subscriptions:expire` is the first writer of `past_due`, `grace_ends_at` and `canceled`.
Two consequences had been invisible: there was **no grace period at all** — `isUsable()`
reads a column nobody set — and **MRR counted every shop that had ever paid**, for ever,
because nothing ever left `active`. A free plan is exempt: a zero-price subscription is not
late, it is free.

**Failing closed where it matters.** A fallback plan that resolves to no row throws rather
than assuming unlimited; in tests that also stops a suite which forgot its fixture from
passing unmetered, which is how a create path that never calls `consume()` stays green for
ever.

`bin/check-quota-scoping` joins the lint job: these tables carry RLS but no
`BelongsToTenant`, so nothing adds a tenant filter and an unscoped query inside
`runAsPlatform()` returns every shop's rows — on a screen that reads "usage", that is one
shop's numbers shown to another.

`docs/DECISIONS-FOR-REVIEW.md` records the judgement calls made in this run, including why
CI has no fork-based concurrency test and what stands in its place.

**No release** — still no production server.

## 0.14.4 - 2026-08-30

**The quota kernel — the shared contract eighteen modules will call, and nothing yet
calls.** No behaviour changes: `App\Support\Quota` now holds the `QuotaGuard` interface,
the `Metric` value object and its registry, the verdict DTO, the two events, and
`PeriodClock`. Platform binds the real implementation next; until then the container
answers with `NoQuota`, bound with `bindIf` so provider discovery order cannot decide which
one wins — the failure mode there is not a crash but a product whose limits silently do
nothing (CLAUDE.md, the `PartyExposure` incident).

Two simplifications the monthly-window decision unlocked, both recorded in ADR 0018 rather
than left as drift: there is **no `MetricKind` enum** (with `Day` gone, the window already
carries counted-versus-computed exactly, and `Metric`'s constructor enforces the pairing
where it can actually be wrong), and the clock is a new `PeriodClock` rather than a move of
Reporting's `ShopClock`, which answers a different question and has ten call sites that
moving would churn.

**One real trap found while building it.** `Jalali::startOfMonth()` returns the first of the
Jalali month at midnight **UTC** — 03:30 Tehran. That is right for the period key, whose
date part *is* the key, and wrong for the refill instant, which is midnight Tehran, three
and a half hours earlier. Returned unchanged, the screen would have promised a refill after
the counter had already reset. The test asserts the time of day and not only the date,
because a date-only assertion passes either way.

`config/hamyar.php` arrives with it: the fallback plan for a lapsed shop, the warning
threshold, and the daily ceiling on system SMS the platform pays for. A missing fallback
plan throws rather than assuming unlimited — the lenient reading would hand every lapsed
shop everything, failing open in the one layer whose job is to fail closed.

**No release** — still no production server.

## 0.14.3 - 2026-08-29

**An upgrade now actually upgrades.** Two defects on the one path the whole metered-plan
ladder depends on, found while designing Phase 12 and fixed before any of it is built.

**`applyPayment()` never wrote `subscriptions.plan_id`.** A shop pressed upgrade, paid,
and stayed on its old plan: the period was extended, the invoice was marked paid, the
events fired, and the only record that anything had been bought was the invoice's `lines`
snapshot — which is deliberately a human-readable string, not something the settlement path
can act on. So `subscription_invoices` gains a `plan_id` column that says what the invoice
*means* beside the snapshot that says what it *said*, and settlement writes it onto the
subscription, with `plan_changed_at` beside it so support can answer "since when". A
renewal — paying again for the plan you are on — deliberately leaves `plan_changed_at`
alone rather than stamping a change that did not happen.

**The upgrade button 404'd.** `billing/index.tsx` has always posted `plan.code` to
`POST /billing/subscribe/{plan}`, and the route was bound by id. Nothing caught it because
no test had ever posted to that route — the suite drove `BillingService` directly, so
seventeen green billing tests coexisted with a button that could not work. `Plan` now
routes by `code` (unique, immutable once created, and readable in a URL); the Filament
resource is pinned to `id` so panel URLs do not move.

**And a third, found on the way:** a shop with no subscription row that paid got nothing at
all — `applyPayment()` fired its event and returned. It now creates the subscription the
invoice implies. Not reachable through the normal signup flow, which provisions one, but
"we took the money and the shop got nothing" is not a failure mode to leave to chance.

`SubscriptionActivated` also finally has the listener its own docblock promised since
Phase 2: `SubscriptionResolver` is a singleton memoising one subscription per tenant, so
until now the very request that took the payment kept answering from the pre-payment plan.

Six new tests, including the first one in the suite that posts to `billing.subscribe`.

**No release** — there is still no production server.

## 0.14.2 - 2026-08-29

**Decision Gate 6 cleared; ADR 0018 is Accepted.** Still docs only — but two of the owner's
answers changed the design as it had been written, and the second one is the reason this
entry is worth reading before any Phase 12 code lands.

**Every window is a month, not a day.** The design was written to the owner's opening words
(«روزانه تا یه تعداد»); at the gate they replaced it with one credit per feature per Jalali
month, refilled at 00:00 Tehran on the 1st — «دقیقاً مثل پلن‌های فعلی جی‌پی‌تی و کلاد که تا یه
حد مصرف رو رایگان دارن، یه تایمی ریست میشه». So `Window` has two cases, `Month` and `Total`,
and there is no day bucket anywhere. This is worth stating plainly because the mechanism did
not move: the counter was always keyed `(tenant, metric, period_key)` with the clock
computing the key, so a change that reads like a product U-turn cost the matrix and the copy
and nothing else. It also retires the "burst allowance" as a problem rather than deferring it
as a feature — a monthly credit *is* the burst allowance.

**The first rung is free, and it replaces the trial.** `TrialPolicy`, `BASELINE_PLAN_CODE`
and the 14-day trial are scheduled for deletion rather than inversion; a new shop starts
`active` on a zero-price plan with no period, and a lapsed paid shop falls back to the same
place. `messaging.sms` is 0 on it: SMS is the one quota that costs cash per unit, so free
shops send by funding the wallet, which is money. The alternative — paid Basic plus
lapse-falls-to-Basic — was a free rung through the back door, and choosing openly was the
point of the item.

Also recorded: golden rule 7 in `CLAUDE.md` is rewritten (gating is quantity, not
availability), «سازمانی» becomes «نامحدود», `laravel/pennant` is approved for removal, Moadian
is never metered (and is low priority — most shops are tax-exempt and do not want it), and the
platform absorbs the cost of system SMS with a per-tenant daily cap.

**No release.** There is still no production server; `bin/release --deploy` stays suspended.

## 0.14.1 - 2026-08-29

**Docs only — the pricing model is redesigned on paper, not yet in code.** The owner
replaced "a plan is a bundle of modules" with "a plan is how much work per day a shop may
record": every module open to every shop, every kind of work capped by quantity, three
plans forming a ladder. [ADR 0018](docs/adr/0018-metered-plans.md) records the design as
**Proposed** and Phase 12 of the roadmap ends in Decision Gate 6, where the owner answers
sixteen items (whether the first rung is free, the limit matrix, what lapse means, …)
before any code is written.

Why it is a version at all: the release process says every merge to `main` carries its
version, and this one carries something the next reader must not miss — the design also
found that **a paid upgrade does not change the plan today** (`applyPayment()` never writes
`plan_id`) and that the billing page's upgrade button posts a plan *code* to a route bound
by *id*. Both are scheduled as 12.1, a plain bug fix that may land before the gate.

**No release is cut for this version.** There is currently no production server (the owner
is providing a new one); `bin/release --deploy` is suspended until it exists.

## 0.14.0 - 2026-08-29

**BREAKING — the product is now «سامانه همیار».** The owner renamed it. Nothing in the
application breaks, but this release does not finish delivering its own purpose until
somebody edits one line on the box, which is what the prefix is for:

```
APP_NAME="سامانه همیار"      # in /srv/mobishop/.env.production
```

`.env.production` lives only on the box and is excluded from the release rsync by design.
Without that edit the public landing page and the Terms show the new name — those strings
are in the Blade views — while the signed-in application's `<title>` and the from-name on
every outgoing mail still say `MobiShop`, because both read `config('app.name')`. A
rename that is right on the front door and wrong once you log in is worse than one that is
uniformly stale, so the edit is not optional.

### The name

«همیار» in prose, «سامانه همیار» where the name is being introduced — a page title, a
social card, the first line of the Terms. `hamyar` is the latin slug for identifiers.
The GitHub repository moved from `mobishop` to `hamyar`, and the working folder with it.

**The apex domain is unchanged and stays `mobiyar.com`.** It was not part of the rename,
it is DNS plus a wildcard certificate, and golden rule 1b keeps it in `config('app.domain')`
either way.

### The half of this that was nearly a silent outage

A rename is a `sed`, which is exactly what makes it dangerous: it is *fast* and it is
*uniform*, and neither of those is a property you want applied to a production box's
resource names.

Three classes of thing had to keep the old name, and one of them was caught only by
auditing the first commit rather than by writing it:

- **The nginx upstream `mobishop_app`.** It looks regenerated on every deploy and it is
  not. `bin/release` deliberately excludes `docker/nginx/upstream/app.conf` from the
  rsync because that file records which slot is live, and `docker/nginx/templates/` is
  rendered by the container entrypoint only at *start*. Renaming it would have left the
  running nginx holding `fastcgi_pass mobishop_app` against an upstream file defining
  `hamyar_app` — `nginx -t` fails at `bin/deploy` step 7, mid-cutover, with both app
  containers up, which is the precise state `bin/deploy` documents as its worst failure.
- **The compose project name, the database `mobishop` and the role `mobishop_app`.** The
  project name is the prefix of every container and every *named volume*; renaming it
  makes the next deploy create an empty `hamyar_pgdata` rather than find the live one.
- **`/srv/mobishop`, `/var/backups/mobishop`, `mobishop-*.dump`.** Real directories and
  real files. The runbooks had been swept to the new names while the scripts kept the old
  ones — including the rollback command, the one line reached for during an incident.

All of it is now written down as golden rule 1c, because the next person to grep for
`mobishop` will read those leftovers as an unfinished job rather than as load-bearing.

### The brand string nobody grepped for

The first commit swept «موبی‌شاپ» and found it in four places. The string this product
actually *shows a shopkeeper* is «موبایل‌یار», and it survived in twenty-nine lines across
nine Blade views: the landing `<title>`, meta description and og:title, both wordmarks,
all six screenshot alt texts, the FAQ and IMEI copy, the login and registration chrome,
and the whole of the Terms of Service. The public front door and the legal agreement were
still naming the old product. A grep is only as good as the string you guess.

## 0.13.0 - 2026-08-23

**The landing page, rebuilt to the brief it was never actually following.** MINOR — the
public page only; no migration, no application change, no new setting.

Third rejection. ADR 0016 records the first two — dark cinematic (rejected on taste) and
calm navy (came back **بی‌روح**). This one came back «معلومه کلاد کد زده»: the copy read as
unprofessional and the layout as generated.

It was. Down the page: a centred eyebrow over a centred H2 on every section, five
alternating text/screenshot rows, three pricing cards with the middle one dark, a plain
accordion, a dark CTA band, icons in circles. Every one of those appears on a thousand
generated pages regardless of subject.

### It was also not following the owner's own brief

| the brief asks for | the page had |
|---|---|
| six shopkeeper problems | five feature rows |
| six FAQ answers | three |
| an **interactive** IMEI record | three static cards |
| primary CTA «۱۴ روز رایگان شروع کنید» | «امکانات را ببینید» |

The last line is not a design problem. A landing page whose main button does not point at
the conversion its brief names is a broken page, and it had been broken that way since the
section was written.

### What shipped

Eight sections, each owning one Blade partial and one CSS file: hero and its signature,
trust bar, six problem→solution blocks in the shopkeeper's own words, the interactive IMEI
record, the product tour, pricing read from the database, six FAQ answers, closing CTA and
a full footer. `landing.blade.php` goes 428 → 67 lines and is now only a shell.

Bundle: **22.9KB gz CSS, 1.7KB gz JS** against a 180KB budget. Still no animation library,
no WebGL, no scroll-jacking.

### Two faults that were not taste

- **The FAQ promised something the software does not do.** «اگر اشتراکم تمام شود» said the
  account goes read-only and you can still export to Excel. `Subscription::isUsable()` is
  binary and `LoginController` refuses the login outright — a lapsed shop cannot sign in,
  so it cannot export anything. A public promise about a customer's own data that the
  product contradicts on day one of a lapse. Rewritten to what actually happens, with the
  advice to export before the renewal date. The support answer likewise claimed phone
  support on a page that carries no phone number.
- **The signature element played backwards on every desktop load.** It is a module script,
  so it runs after first paint — and first paint is the *finished* state, the stylesheet's
  no-JS default. Setting `data-signature` flipped it to the pile with a 120ms transition
  live, so every visitor watched the tidy ledger fall apart: the page's own argument, in
  reverse, unprompted.

### The interesting failure: four authors, one default

The rebuild was produced by four engineers working in parallel from separate files, and the
shared contract they were meant to build against never arrived. An adversarial review of
the assembled page found they had each killed the centred eyebrow and then **independently
reached for the same replacement** — six of eight sections opening with a heading on one
side and a supporting line on the other. Plus three separate numbering devices (۰۱–۰۶ twice,
40% of the page apart), the same accent-clause headline trick in three sections, and four
guesses at the type scale that arrived as five different H2 sizes.

One template swapped for another. That is worth recording because it is *why* generated
pages look generated: independent minds converge on the same default. The repair was one
pair of eyes on the assembled page — two H2 sizes and a token that says which, the masthead
capped, two of the three numbering devices deleted, the accent clause returned to the hero
alone.

### And CI stopped the whole thing from shipping as a 500

Removing the FAQ's ordinals array left a `{{-- --}}` in its place — inside `@php`, which
parses it as PHP, so `ordinals` became a bare identifier and every route rendering the page
threw a syntax error. The container would have been healthy, the deploy green, and the front
page an error. Three tests caught it.

## 0.12.1 - 2026-08-22

**Two faults in the release tooling itself, both of which made a check look like it was
working.** PATCH — `bin/release` and `bin/smoke` only; no application change, no migration.

Neither of these was found by reading. One took the first real release; the other took
asking the question "what does this loop do when it finds nothing?"

### 1. The detached deploy was not detached

Found by the first real release, which is the only place it could have been found. Twelve
defects had already been fixed after an adversarial review of these scripts; this is the
thirteenth, and no amount of reading was going to catch it.

`remote_detached` launched the remote work like this:

```sh
ssh … "cd '$PATH' && rm -f LOG LOG.done && nohup sh -c '…' >/dev/null 2>&1 & echo detached"
```

In `A && B && C & D`, **the `&` backgrounds the whole `A && B && C` list, not just `C`.**
So the remote shell forked a subshell that ran `cd`, `rm`, and then *waited* on the
`nohup` — with its own stdout and stderr still attached to the ssh channel, because the
`>/dev/null 2>&1` bound to `nohup` alone. ssh will not close a channel a process still
holds, so the launch blocked for the entire remote run.

Everything about it looked right. `detached` came back instantly, the build ran, the
deploy ran, the sentinel was written, the release worked. **And the poll loop below it
never executed a single iteration** — the drop protection this helper exists for was not
there at all. A link failure would have killed the remote command exactly as it did on
2026-08-21, the fault the helper was written to prevent.

Measured rather than reasoned: a 30-second remote command returned in **32.4s** under the
old form and **2.4s** under the new one, with the remote work going on to completion after
the channel closed. Three things make it work, and all three are load-bearing —
`{ … & }` so only the `nohup` is backgrounded and `cd`/`rm` failures are still reported;
`< /dev/null > /dev/null 2>&1` so the background process holds none of the channel's
descriptors; and `setsid` so tearing down the ssh session cannot signal it (`nohup` covers
SIGHUP, not the session).

The wrapped command also moved from `{ … }` to `( … )`. A command that calls `exit` in the
grouping form exits the whole wrapper, so `echo $? > LOG.done` never runs and the poller
waits out its full thirty minutes on a command that finished in seconds. `bin/deploy` is a
separate process and was never exposed to it; the subshell removes the edge anyway.

Both paths were then exercised against the production box before this shipped: a
25-second success detected by the poll loop, and a genuinely failing `docker build`
reported with the box's own error text and a non-zero return.

#### This is what the repository already says about deploy-layer bugs

`CLAUDE.md` argues there is no staging box because Phase 11.4 found eleven faults on real
hardware and *not one of them was reachable from a local test*. This defect is the same
shape: green CI, a passing review, a working release — and a safety mechanism that was
decorative. It took running the real thing against the real box to see it.

### 2. The landing's front-door check could pass having tested nothing

The check that follows the landing page's calls to action matched only absolute
`https://…` hrefs, and then simply looped over whatever it found. Two ordinary changes
turn that silent, and neither is a mistake anybody would think to announce:

- the landing switches its buttons to relative paths (`/login`) — perfectly reasonable
  markup, arguably better than absolute;
- a redesign drops or renames the calls to action.

Either way `grep` matches nothing, the loop body never runs, **no check is reported**, and
smoke declares the front door healthy having tested no part of it. That is exactly the
shape of fault this script exists to catch: a gate that passes because it cannot see.

Relative hrefs are now resolved against the apex, and the number of links found is
asserted rather than assumed — zero is a failure with its own sentence. The landing is the
only page a prospective customer ever reads, and it having no working way in is not a
state to learn about from a support message.

Exercised on all three inputs before shipping: today's absolute links pass, relative links
now pass where they were previously invisible, and a landing with no way in fails.

## 0.12.0 - 2026-08-22

**A change that is finished is now a change that is live.** MINOR — the first versioned
release. No migration beyond the three RLS read policies described below, no new
`.env.production` key.

This release exists because of the state it found the project in. Twenty-one finished
commits were sitting on `feat/landing-immersive`; production was serving the release from
five commits earlier; `app.<apex>/login` offered a «ثبت نام» link pointing at the landing
host, where `/register` does not exist; and **every check was green**. Nothing was
broken in a way any gate could see. The gap was between "the code is correct" and "the
correct code is what is running", and nothing in the repository measured it.

### Shipped

- **The landing page carries weight.** ADR 0016's direction, built out: a scroll spine,
  real typographic hierarchy, and copy that stopped selling. The previous page was calm,
  correct and بی‌روح.
- **«ثبت نام» resolves.** It was hand-built from `config('app.domain')` — correct while
  login lived on a shop's hostname and sign-up on the central domain, and wrong from the
  moment ADR 0017 put both on `app.<apex>`. The page rendered perfectly and the link
  404'd on click, which is exactly the fault an assertion on a substring cannot see, so
  the test that replaces it *follows the href*.
- **ADR 0017 finished.** One host for the application, tenant resolved from the session
  rather than from the hostname. 63 test files, the shared test helpers, and the
  production code the migration turned out to reach: `ResolveTenant`, the Identity
  routes, impersonation, password reset, invitations, `PublicTenantResolver`,
  `PriceListAccess`. Three migrations open **platform-scoped read** policies on
  `invitations`, `repair_tickets` and `storefront_settings` — the public surfaces those
  serve (accepting an invitation, tracking a repair by QR, a public shop page) no longer
  have a hostname to resolve a tenant from. `tenancy:check` and the cross-tenant
  isolation suite are what prove they did not widen anything.
- **Dashboard query work.** The non-sargable date expression, the `select *`, and the
  N+1 that issued 20,600 calls — the three causes traced in the 2026-08-20 load test,
  where `/dashboard` came back at 2.03s p95 against a 1000ms threshold. PATCH-shaped work
  inside a MINOR release: it makes an existing screen do the same thing faster.
- **The CSP nonce is asserted where it can exist.** `/login` is Blade since ADR 0016 and
  carries no inline script, so asking its markup for a nonce could only fail. Split: the
  policy's shape is asserted on `/login`, the stamping on `/design` — and the new
  assertion compares the markup's nonce to the header's, because a nonce that does not
  match is worse than one that is missing. The browser drops the script either way, and
  each half reads as correct alone.

### The release process itself

`VERSION`, this file, `docs/VERSIONING.md`, `docs/RELEASE_PROCESS.md`, `bin/release` and
`bin/smoke`. Modelled on the sibling invoice-system project, which has run this way for
241 releases.

Three of its rules are load-bearing and were chosen against the obvious alternative:

- **A release is not a commit.** `VERSION` and the changelog entry are written in the same
  pull request as the change they describe, so `bin/release` only ever *publishes* what
  `main` already holds. Bumping the version at release time would mean a direct push to
  `main` — and therefore `ALLOW_MAIN_PUSH=1` as routine, which is not a guard.
- **`bin/release` refuses to run while a green pull request sits unmerged.** That is the
  exact state that produced this release's opening paragraph, so it is a failure with a
  named override rather than a warning. A warning is what gets ignored.
- **A deploy is not a release until `bin/smoke` says so.** It runs from outside the box,
  over the real TLS, and asserts the version the box reports back — plus the two links a
  shopkeeper actually clicks, followed rather than matched. It would have caught the 404
  above on the day it shipped.

`/health` now reports `version` (public) and, behind `X-Health-Secret`, the exact image
tag serving the request. That answer — *which build is live* — is what turns "the site is
still broken" from a guess into a fact.

### The repository is public

Owner's decision, 2026-08-22, taken with the consequences on the table: this publishes the
whole history — 178 commits, 72 revisions of the roadmap, the business plan, the security
document and the specs of all eighteen modules. A secret audit ran first and came back
clean: `.env` and `.env.production` have never been committed, no key, token or host
appears in any commit, and the only credential-shaped string in the tree is a `<token>`
placeholder in the deploy runbook.

It has one consequence worth more than the reason it was done for: **branch protection and
rulesets are Pro-gated for private repositories and free for public ones.** `main` now
requires a pull request and all five checks at the platform level. `CLAUDE.md` said in
plain words that nothing here was mechanically enforced — that paragraph was accurate and
is now rewritten, with the condition under which it must be written back.

### The release scripts were reviewed before they were trusted

`bin/release` and `bin/deploy` went through an adversarial review before `bin/release` was
allowed near production: 23 candidate defects, each handed to an independent reviewer told
to refute it, **12 confirmed**. Three were critical.

The worst was not a failure mode — it was the happy path. `git tag` creates the ref
locally, so the tag-exists guard made the script single-shot: the two-step usage it prints
at the end of a non-deploy run (`bin/release`, then `bin/release --deploy`) could never
work, on a perfect network. And any failure after the tag left a published GitHub release
announcing a version the box was not serving, with the only command that deploys refusing
to run. It now resumes when the tag points at `HEAD` and still refuses when it points
anywhere else — which was the guard's actual purpose.

Second: a `bin/deploy` failure after the cutover was reported as *"nothing was cut over"*.
Steps 8 and 9 run after traffic moves, and the trap said the same thing either way — so the
exact fault described below would have been reported as its own opposite.

Third: three gates failed **open**. `|| true` and `|| echo 0` on queries whose empty result
already means "nothing wrong", so a GitHub API hiccup between the CI check's three requests
printed `✓ all N checks passed` for a commit still running its tests. Every gate now tests
the exit status, never the emptiness of the output.

And the tree that ships is now the tag: `rsync` sent the working copy — untracked and
gitignored files included — while the tarball and its SHA-256 described something else and
asserted it with a checksum.

### And one thing this release learned by breaking

The first deploy cut through this path lost its SSH connection partway through
`bin/deploy`. Because the command was a child of the session, it died with it: traffic had
already been cut over to the new container, and steps 8 and 9 never ran — **horizon and the
scheduler were left down, on the old image**, with nothing red anywhere to say so. Compose's
mid-recreate rename had also left a container holding the name `mobishop-horizon-1`, so the
obvious retry failed with a name conflict on top of it. The site served perfectly; it just
stopped sending SMS.

`bin/deploy`'s ordering guarantee — every irreversible step after every reversible one — is
about the order of the steps. It buys nothing against the script simply stopping between
two of them, and a dropped carrier is the one thing that can do that from outside. So
`bin/release` now runs both remote steps **detached**, with `nohup`, writing an exit code to
a sentinel file it polls for. A dropped link costs a reconnect instead of a half-deployed
box. `bin/deploy`'s header says so too, because that is where somebody will be reading when
it matters.
