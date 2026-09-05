# Lessons — why the rules in CLAUDE.md say what they say

Every rule in `CLAUDE.md` is one line because it has to be read every session. Every rule
here is a paragraph because it was paid for once and should not be paid for twice.

Read this when a rule looks arbitrary, when you are about to argue with one, or when you
have just been bitten by something and want to know whether it has happened before.

The pattern to notice, because it is most of this file: **almost none of these produced an
error.** The wrong implementation quietly won a name, a guard silently passed, a message
went to the wrong place, a counter was right and the screen was wrong. Loud failures get
fixed the day they appear. These are the other kind.

---

## Tenancy

### Session-scoped `set_config`, never `SET LOCAL`

`SET LOCAL` is transaction-scoped, and Laravel does not wrap a request in a transaction.
So the transaction-scoped form silently sets *nothing* and every tenant query returns zero
rows — no error, just an empty application.

Session scope is the fix and it has a cost: the value must be cleared at every boundary.
Four are covered and tested (ADR 0007) — end of request, around a queued job,
`TenantContext::runFor()`, and connection (re)establishment.

RLS policies read `current_setting('app.tenant_id', true)` and use both `USING` and
`WITH CHECK`, so an unset context denies everything rather than erroring. The layer fails
closed, which is the only acceptable direction for the product's one hard guarantee.

### `mobishop` identifiers on the production box are load-bearing

The product was renamed from MobiShop to «سامانه همیار» on 2026-08-29. Several production
identifiers deliberately still read `mobishop`, and each one names a resource that already
exists on the box, where a rename in the repo is a silent break rather than an error:

- **The compose project name in `compose.prod.yaml`** — it prefixes every running container
  and every **named volume**. Rename it and the next deploy creates an empty `hamyar_pgdata`
  instead of finding the live one.
- **The database `mobishop`**, its root role, and the app role `mobishop_app` that the RLS
  guarantee depends on.
- **`/srv/mobishop`, `/var/backups/mobishop`, the `mobishop-*.dump` prefix** — renaming the
  prefix alone also strands the retention sweep in `bin/backup-nightly`.
- **The nginx upstream `mobishop_app`.** This one is the trap: it looks regenerated on every
  deploy and is not. `bin/release` deliberately excludes `docker/nginx/upstream/app.conf`
  from the rsync (it holds which slot is live), and `docker/nginx/templates/` is rendered by
  the entrypoint only at container *start*. Rename it and the running nginx keeps
  `fastcgi_pass mobishop_app` while the upstream file says `hamyar_app` — `nginx -t` fails
  mid-cutover, with both app containers up.

Renaming any of these is a coordinated migration on the box, not an edit here.

---

## The savepoint family — four occurrences, none of which crashed

Postgres aborts the **entire** transaction on a constraint violation. Everything below
follows from that one fact, and every version of this bug presents as something other than
what it is.

### 1–2. Catching 23505 needs a nested transaction

`AbandonedSweep::insertOnce()` and `SendSms::record()`. Catching a unique violation inside
an outer transaction leaves that transaction dead, and every later statement fails with
`25P02: current transaction is aborted`. Wrapping the insert in `DB::transaction()` gives it
a SAVEPOINT to roll back to.

The second occurrence presented as **twelve unrelated tests failing** after the one that
actually collided. Every test runs inside `RefreshDatabase`'s transaction, so this is not an
edge case — it is the default condition.

### 3. The `try` goes OUTSIDE the transaction

`SubmitInvoice::enqueue()`. `DB::transaction()` releases its SAVEPOINT when the closure
*throws*; a closure that catches its own exception never triggers that, so the recovery query
runs on a still-aborted connection and dies with the same 25P02 the wrapper was added to
prevent.

```php
// WRONG — the catch runs inside the aborted nested transaction.
DB::transaction(fn () => { try { insert(); } catch { select(); } });

// RIGHT — the closure throws, the savepoint rolls back, the catch runs on a healthy one.
try { DB::transaction(fn () => insert()); } catch { select(); }
```

### 4. A `finally` that issues SQL is the same bug wearing a different hat

`UsageEvents::write()`, 2026-08-30. The `try` was outside `transaction()` exactly as above —
and a `TenantContext::runAsPlatform()` sat *inside* the closure. That helper restores its
flag in a `finally`, and a `finally` is not a `catch`: it runs on the way out of the failed
insert, while the transaction is still aborted and before `transaction()` reaches its
ROLLBACK. So `set_config` died with 25P02 and **that exception replaced** the
`UniqueConstraintViolationException` — so the catch written to handle the duplicate never
matched.

What it cost: a shop saw the quota block once per metric per month, and a white 500 every
time after. On exactly the "operator presses submit again with a customer waiting" case the
block exists to handle well.

```php
// WRONG — the finally's set_config runs on the aborted transaction and masks the 23505.
try { DB::transaction(fn () => $ctx->runAsPlatform(fn () => insert())); } catch (Dup) {}

// RIGHT — rollback first, then the flag is restored on a healthy connection.
try { $ctx->runAsPlatform(fn () => DB::transaction(fn () => insert())); } catch (Dup) {}
```

`bin/check-savepoint-recovery` now fails the build on both shapes. It found a fifth instance
the day it was written, in `TenantProvisioner`, where the nesting is genuinely unavoidable
and carries its reason in a `savepoint-allow` comment.

---

## Things that silently win the wrong name

### A guarded global helper must not collide with a dependency's

`App\Support\helpers.php` defined `jdate()`. **morilog/jalali defines one too**, both
`function_exists`-guarded, and the package's is autoloaded first. Ours was dead for eight
phases and looked live: `jdate($t)` returned `1405-06-02 21:18:47` where every screen in this
product shows `۱۴۰۵/۰۶/۰۲`. Nothing used it until a Blade view did.

Renamed to `jalali()`. Before adding a global helper, grep the vendor tree for
`function <name>`. Enforced by `bin/check-global-helpers`.

### A null-object default is bound with `bindIf`, never `bind`

Module providers are discovered in directory order, so a default and its real implementation
binding the same interface with `bind` means the last writer wins — and which one that is
depends on a directory listing.

The symptom is not a crash. It is **a guard that silently passes**, half the time, on half
the deployments. `Cheques` binding `PartyExposure` lost to CRM's `NoPartyExposure` exactly
this way, and the credit check went on approving customers it should have stopped.

### `QuotaExceeded` must not extend `RuntimeException`

A dozen controllers wrap their domain call in `catch (RuntimeException $e)` and turn it into
a field-level validation message — the established way this codebase reports «موجودی کافی
نیست» beside the input that caused it. Every one of those arms swallowed the quota block on
its way past.

At the till, a shop that hit its monthly cap got the raw English
`Quota exceeded for [sales.invoices]: 300 used of 300, 1 requested.` under the line-items
field, and `quota_block` — the Persian sentence, the reset date, the upgrade button — never
reached the page at all.

Nothing crashed. The refusal was correct, the transaction rolled back, no credit was spent;
only the *telling* was wrong, which is why every counter-based test passed. Extending
`Exception` puts it outside those arms **by construction**, including in controllers nobody
has written yet — where adding `catch (QuotaExceeded) { throw; }` above a dozen existing arms
works today and silently stops working at the thirteenth.

---

## Forms and what the operator is told

### Every form needs a home for errors that belong to no field

A validation failure on `accessories` or `lines` has nowhere to render beside an input, so
without a general error region the submit button silently does nothing — and the operator,
with a customer at the counter, presses it again and concludes the software is broken.

An audit of all 34 submitting components (0.18.0) confirmed **24 orphan keys across 9 files**.
`PosSaleRequest` alone can return twelve the POS screen could never display; its error region
was three hardcoded keys and `PaymentBox` was not passed the error bag at all.

`<FormErrors>` takes the **whole error bag** rather than a list of keys, and that inversion is
the design: a component you must tell which keys to show needs updating every time somebody
adds a rule, and the keys nobody thought to place are exactly the ones that go missing.

### A multipart form gets a test that omits its optional-array keys

A `FormData` body cannot express an empty array: an unticked checkbox group is not sent as
`[]`, it is not sent at all. So `present`/`required` on an optional array rejects the ordinary
case — and only a test that omits the key entirely catches it, because building the payload in
PHP always includes it.

### An empty PHP array is a JSON *array*, and JSON arrays are truthy

`HandleInertiaRequests::usage()` returned `[]` when there is no tenant. That crosses the
boundary as a JSON array — truthy in JavaScript, with no `attention` property — so
`UsageBanner`'s `!usage` guard waved it through into `usage.attention.length` and threw.

`/design` renders in the shell without a tenant and had been throwing an uncaught TypeError
since the prop shipped in 0.15.0. Every browser test until then visited a page that has a
tenant. Return `null` for "nothing to report", and defend in the component anyway, because a
shared prop has many writers.

### Persian validation messages are the default, not a per-request afterthought

There was no `lang/` directory at all until 0.18.0. Locale is `fa` and the fallback is `en`,
so Laravel answered every validation failure from its own English file inside `vendor/`: a
shopkeeper who left a field blank read *"The identifier field is required."* — left-to-right,
naming a database column, on a right-to-left page.

It hid for eighteen releases because 21 of the 24 FormRequests hand-write Persian for the
rules somebody remembered (121 keys). Every path anybody tested was Persian; every path nobody
anticipated was English. Plus 40 inline `$request->validate()` calls with no messages at all.

The `attributes` map is the half that matters most: without an entry, `:attribute` renders the
raw column name mid-sentence. **When you add a validated field anywhere, add its label to
`lang/fa/validation.php`.**

---

## Process

### Read the checks, never `mergeStateStatus`

`mergeStateStatus` answers *may this branch merge*, and with no required checks it returns
`CLEAN` before CI has even been queued, because nothing is required. That is how #38 merged
with zero checks reported; they happened to pass afterwards, which is luck rather than a gate.

Required checks exist now, so the field is no longer permanently optimistic — but it still
answers "may this merge", never "is this correct".

```bash
# WRONG — CLEAN just means "nothing is blocking", including "nothing has run".
until [ "$(gh pr view "$n" --json mergeStateStatus -q .mergeStateStatus)" = CLEAN ]; do sleep 30; done

# RIGHT — wait for the jobs themselves, then read their verdicts.
until [ "$(gh pr checks "$n" | grep -c pending)" = 0 ] && [ "$(gh pr checks "$n" | wc -l)" -gt 0 ]; do sleep 30; done
gh pr checks "$n" | grep -qv $'\tpass\t' && echo "not merging" || gh pr merge "$n" --squash --delete-branch
```

### A change that is not on the box is not done

On 2026-08-21 the landing rebuild and the fix for a live `/register` 404 sat finished on a
branch while production served the release from five commits earlier. **Every check was
green.** Nothing in the repository measured the distance between "the code is correct" and
"the correct code is running", so the only way to discover it was for the owner to open the
site and find it still broken — the most expensive possible place to find out, and the one
that reads as "the software does not work".

`bin/smoke` is the only thing that knows the difference between a merge and a deploy.

### Never tick a box for behaviour no route reaches

A service whose tests pass but which only Tinker can call is not a shipped feature.

Found the hard way (0.16.0): across 104 write routes, nothing creates a cheque, an expense, a
recurring template, a rental contract, a campaign or a treasury account. Each had its service,
its ledger matrix, its events and its tests — a `Cheque` row is written in nine test files and
**zero** production files — and each was priced on the plan ladder, so the free rung advertised
«۵۰ ثبت چک» for something a shop cannot do once. Three ticked boxes in Phases 7 and 8 had been
claiming otherwise for months. See roadmap 12.16.

Ask whether a shopkeeper can do the thing. If the answer needs a terminal, the box stays open
with a reason beside it.

### Branch protection is mechanical *now*, and was not always

The CLAUDE.md paragraph asserting protection said the opposite until the repository went
public, and it was right to: rulesets and branch protection are Pro-gated for *private*
repositories, so while this one was private the platform enforced nothing. Public made them
free (2026-08-22).

**If protection is ever removed, or the repository goes private again, that paragraph goes
back.** A rule everybody believes is mechanical is one nobody checks. `enforce_admins` is
deliberately off, so the owner keeps an escape hatch; same philosophy as `ALLOW_MAIN_PUSH=1`.

Two things still back it up, and neither depends on the platform:
`.githooks/pre-push` (installed by `make hooks`) refuses a direct push to `main` before it
reaches the network — prevention where the ruleset is rejection, saying *why* rather than
returning a remote error, and it works offline. `.github/workflows/guard-main.yml` is the
detector: a red build if a commit ever reaches `main` without a PR. The rule was broken once,
in Phase 10, by finishing a merge and starting the next phase without branching.

### There is no staging box, deliberately

Staging and production run identical software; the only thing distinguishing them is what the
data is worth. With zero customers, a second box costs money to teach us nothing.

Phase 11.4 is the evidence in the other direction: deploying to real hardware found **eleven
faults** — WAL archiving that had never once succeeded, a certbot container with no DNS
plugin, an nginx never reloaded after renewal — and not one was reachable from a local test.
Deploy-layer bugs only exist on the deploy layer.

**The tripwire is the first real shop.** The day one paying customer's data lands on a box,
`migrate:fresh` becomes unthinkable, the load test needs its own machine, and a second box
must exist *before* the next risky deploy rather than after the first incident. A stale
"no real customers yet" is the most expensive sentence this repo could contain, because it
reads as permission long after the permission ended.

---

## Quota — the counter was right every time

Five bugs across `0.16.0`–`0.18.0`, and in every one the arithmetic was correct and what the
shop was **told** was wrong:

1. The block swallowed by every `catch (RuntimeException)` (above).
2. The second block of any month returning a white 500 (the `finally` bug, above).
3. `identity.users` counted revoked invitations as occupied seats, so a shop at its cap that
   mistyped a mobile number was locked out for seven days while its own screen showed the
   invitation as «لغو شده».
4. Every `Total`-window metric — seats, storage, branches, live price-list links — was
   promised a monthly reset it never gets. `resets_at` was already null, so the card said
   "this month" and then declined to name the date.
5. The blocked-shops widget could not see shops refused on their **first** attempt, because
   `blocked_at` was stamped with an `UPDATE` and no counter row existed yet. The metric most
   often capped at zero is `messaging.sms` on the free rung — every shop that has never paid —
   so the shops most worth talking to were the only ones invisible.

The lesson is one line: **this subsystem's tests all reach for the counter, because the
counter is the thing with a number in it. Assert on what reaches the operator.**

---

## Editing code with scripts

### Never brace-match PHP with a regex

Removing sixteen verified-dead methods with a Python regex that found the signature and then
walked braces deleted **1,325 lines instead of ~120**: the whole `InvoiceStatus` enum
including its three cases and `labelFa()`, 242 lines of `TenantProvisioner`, 171 of
`AuditSubjects`. The bug was `(?:/\*\*.*?\*/)?` under `re.S` — `.*?` is lazy but `re.S`
lets it start at the file's *first* docblock, so "the preceding docblock" became "everything
from the top of the file".

It ran clean. `php -l` passed on every file, because a gutted enum is still valid PHP. Only
the `git diff --stat` line counts gave it away — "242 deletions for one method" is not a
number a correct edit produces.

Use `token_get_all()` instead: find `T_FUNCTION`, confirm the next `T_STRING` is the name,
walk back over modifiers and at most one `T_DOC_COMMENT`, then count `{`/`}` as *tokens*
rather than characters. Braces inside strings and comments are not tokens, which is the
entire reason the regex could never have worked.

**And read the diffstat before trusting a bulk edit.** Every file had passed a syntax check;
the only signal that anything was wrong was the size of what disappeared.

## Testing

### `??` cannot tell null from missing

Three separate assertions this project has written were silently vacuous because `$x['k'] ??
'missing'` returns `'missing'` when the value is *legitimately* `null` — an unlimited quota
limit, an absent `usage` prop. Use `array_key_exists()` when null is a meaningful value.

### Parallel agents need parallel schemas

Six agents writing tests against one Postgres raced on `RefreshDatabase` and produced
`relation "migrations" does not exist` in files nobody had touched, costing a round of false
diagnosis. Give each its own `DB_DATABASE`.

### The laptop's suite is not CI

It has ~17 failures CI does not — Persian `LIKE` terms producing invalid UTF-8, a local
mbstring difference. Never treat a local red as authoritative: diff against `main` on the same
machine, or trust CI.

### A security sentinel must not be able to occur by accident

`PasscodeSecurityTest` proved the device unlock code never reaches a rendered page by
grepping the whole Inertia payload for it. The sentinel was `'4517'`, a realistic four-digit
PIN — and every page payload carries `auth.user.mobile`, eleven random digits from the
factory. Measured over 200,000 samples, a generated mobile contains `4517` in **0.067% of
runs**, before counting the tenant subdomain, the generated e-mail, and every other random
digit in the props.

So the file failed in CI with no leak, no code change, and nothing to find. It surfaced on a
PR that touched three React files — which cannot reach `viewData('page')` at all — and the
first instinct, in the moment, is to re-run it.

That instinct is the actual damage. **A security assertion that cries wolf is worse than no
security assertion**, because the learned response to a red run becomes "re-run it", and that
is precisely how a genuine leak eventually gets waved through on the third amber. The cost is
not the wasted CI minute; it is that the test stops being believed.

The sentinel is now `'Qx7-4517-Lm2'` — still a plausible device password, since an
alphanumeric screen lock is ordinary, and impossible to produce from random digits. It keeps
the original digits so its history stays legible. Every assertion is otherwise unchanged, and
the fix was verified the only way that counts: by planting a real leak in the controller and
watching the test fail on it.

The general rule: **a test that searches for a needle must use a needle that cannot grow in
the haystack.** Anything short enough to be realistic is usually short enough to collide, so
make the sentinel unmistakable and say why in the file.

### An icon that names a physical direction is already correct in both directions

Every «بازگشت» link in the product outside `PageHeader` — thirteen files across Purchasing,
Catalog, CRM, Inventory and settings — rendered its arrow pointing the wrong way.

They wrote `<ArrowRightIcon className="size-4 rtl:rotate-180" />`. In a right-to-left page a
back link points toward the reading *start*, which is the physical **right**, and
`ArrowRightIcon` already points there. The variant turns it around: the icon computes
`rotate: 180deg` and points physically left, which is forward. So the arrow on a link
labelled «بازگشت» pointed away from where the link went.

It survived thirteen reviews **because it reads as careful RTL work.** Mirroring an icon is
usually right, and the identical class two files away in `Pagination` is correct there —
that component picks the LTR icon for "previous" and mirrors it deliberately, so in an RTL
page previous points right, where the reader's eye came from. Same class, opposite
reasoning, and only one of them is a bug.

Two things this cost, worth remembering separately:

**The first measurement said there was no bug.** `getComputedStyle(svg).transform` returned
`none` everywhere, including on the files that carried the class — because Tailwind v4
compiles `rotate-180` to the standalone `rotate` property, not to `transform`. Reading the
generated CSS rather than trusting the probe is what turned "the variant does nothing" into
"the variant does exactly what it says". A negative result from tooling is a claim about the
tooling until it has been checked against the source.

**And the plan had it backwards.** Its Phase 15 icon audit reads «`rtl:rotate-180` on every
directional glyph» — as though adding the variant were the fix. Removing it is.

Gated by `bin/check-rtl-arrows`, which refuses `ArrowLeftIcon`/`ArrowRightIcon` carrying the
variant and deliberately says nothing about chevrons.

---

## Interface

Six lessons that were paid for during the 2026-08/09 UI work and recorded only in
`docs/design-system.md` or `docs/PROGRESS.md`, promoted here so the file that explains the
rules is not missing the most expensive ones.

### A defined token is not evidence of a generated class

The z-index tokens were declared as `--z-sticky` … `--z-toast`. Tailwind v4 builds `z-`
utilities from the `--z-index-*` namespace, so those declarations generated **no CSS at
all** — and `app-shell.tsx` asked for `z-sticky` on the sticky header from the day it was
written, getting a class that did not exist and a header with `z-index: auto`. Nothing
errors when a utility is absent. Grep the built stylesheet for the class; a token block that
looks right is not proof.

### 40px means 40px, and the label is the target

`button.tsx` used to name filter chips, toolbars and table rows as cases that could ask for
`sm` (28px), contradicting the accessibility floor one file away. A scan of twenty-three
screens found 35 controls under it. Settled 2026-08-31 in favour of the floor: anything a
person taps is `default` or larger; `sm`/`xs` exist for controls nobody taps. The target and
the control are not the same box — a 20px checkbox inside a 40px label row is correct, and an
unlabelled expanded target says so in `data-hit-area="expanded"` because a pseudo-element is
invisible to anything that measures.

### Paper is a light island

Dark-mode `success` `#4cc47f` is 7.5:1 on `#1d1d1f` and **2.2:1 on white**. A print sheet is
ink on white in both themes, so the moment a shop switched to dark mode every «پرداخت‌شده»
stamp and every positive figure on an invoice went from readable to nearly invisible. Every
semantic token now restores to its `-on-light` step inside `[data-paper]`, once, in
`app.css`. Adding a semantic token means adding its `-on-light` step and its `[data-paper]`
line; faking a sheet with `bg-white text-black` fixes the ground and leaves every badge on
its dark step.

### A second elevation ramp hides beside the first

The tokens described two shadows. Dropdown, select and popover reached for Tailwind's
`shadow-md`, the sheet for `shadow-lg`, each with a `ring-1 ring-foreground/10` bolted on —
a second ramp with different colour, spread and no dark-mode thinking, running beside the
one the tokens named. The missing step was `--shadow-mid`; the rings went with it. When a
component reaches past the tokens, the tokens are missing a step, not the component a rule.

### `rounded-card` was spelled twenty-five ways

141 sites hand-rolled the card surface: three grounds, five padding scales, `border` beside
`border border-border` (the same thing). A primitive that exists but is not the path of
least resistance does not get used. `Card` now owns radius, hairline and padding, with
`ground` / `padding` / `elevated` variants, and the design system says surfaces use it.

### The sweep counted buttons and never anchors

Every register's rows were clickable and unreachable by keyboard — `cursor: pointer` plus
`onClick`, no tab stop, role or key — and 17px dashboard links sat under the 40px floor.
Thirteen phases of sweeps missed both because the target scan only ever counted `button`
elements; adding anchors lit up 112 of 344 cases. A measurement is only as good as the
population it measures; when a sweep passes cleanly, check what it did not look at.

### A directive name inside a Blade comment deleted `</head>`, and the page still returned 200

The landing's structured-data block carried a comment explaining why it used a php block
instead of the json directive — and it wrote both names with their `@`. Blade extracts raw
php blocks **before** it strips comments, so the `@php` inside the prose opened a block
that closed on the real `@endphp` sixty lines below. The comment's own `--}}` was inside
that extracted region and therefore invisible to the comment stripper, which ran on to the
next terminator it could find, forty lines further down. Everything between vanished from
the compiled template: the structured data, the `@vite` call, `</head>` and `<body>`.

Nothing failed. The route returned 200, the page rendered, and the missing pieces were only
visible by counting them in the response. **Never write a Blade directive name inside a
Blade comment** — say "a php block", not the spelling with the `@`. The regression test is
structural: the landing must contain `</head>` and `<body>`, because that is the assertion
this bug could not have passed and no content assertion would have caught.

### The captcha's answer was in the DOM, and the test that checked for it passed

The «کد امنیتی» drawing set each character with an SVG `<text>` element. The feature test
asserted `assertDontSee($code)` and was green — the five characters are separated by markup,
so the code never appears as one contiguous string in the HTML. Meanwhile:

```js
document.querySelector('[data-security-image]').textContent   // "WW6CA"
```

One line, no OCR, no image processing at all. The obstacle a scripted login had to clear was
reading a string out of the page it had just downloaded, in front of the only door this
product has. It shipped that way for the captcha's whole life.

Two lessons, and the second is the general one.

**A picture of a string must not be made of the string.** The glyphs are `<path>` outlines
now — a hand-authored stroked alphabet in `SecurityCode::GLYPHS` — so the only route back to
the answer is to look at the drawing. Vector rather than a GD raster because GD needs
FreeType *and* a TrueType face, this repository ships `woff2`, and a captcha that fails to
render is a shop that cannot sign in.

**Assert the property the way an attacker would test it, not the way the markup happens to
spell it.** `assertDontSee` asked "is this substring in the response", which was never the
question; the question was "can the answer be read out of the page". That is
`strip_tags($svg)` being empty, and it is now what the test says.

### A toggle with no state of its own reads as a toggle that does not work

The owner reported the password show/hide button as broken. The click was bound, firing, and
flipping `input.type` correctly the whole time. What was missing was any way for the button
to say so: one static open eye, no `aria-pressed`, no pressed colour. So over an **empty**
field — which is how anybody reviewing a form presses a button for the first time — pressing
it changed nothing on the screen whatsoever, and the honest conclusion from that is that the
control is dead.

A control whose only feedback is a side effect somewhere else has no feedback when the side
effect is invisible. The state lives on the button now (`aria-pressed`, two icons swapped by
CSS off that attribute), which fixes the screen-reader case in the same move.

The button also ships `hidden` and `landing.js` reveals it. A control that cannot work
should be **absent**, not inert: an eye that does nothing is indistinguishable from broken
software, and that is the report this whole entry came from.
