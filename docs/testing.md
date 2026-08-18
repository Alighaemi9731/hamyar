# MobiShop — Testing policy

The rule that overrides every other consideration: **a roadmap task is not done until
`composer test` is green**, and **no tenant-scoped endpoint ships without a
cross-tenant isolation test**. Both are in CLAUDE.md as golden rules 8 and 1.

---

## 1. The gate

```bash
composer test            # pint --test → RTL gate → larastan → pest
composer test:isolation  # the cross-tenant suite on its own
```

`composer test` runs four steps, cheapest first, so a broken build tells you why in
seconds rather than minutes:

| Step | Command | Fails on |
|---|---|---|
| Style | `pint --test` | Formatting drift |
| RTL | `php bin/check-direction-classes` | Any physical direction class |
| Static | `phpstan analyse` | Larastan level 8 findings |
| Tests | `pest` | Arch, unit, feature, isolation |

CI ([.github/workflows/ci.yml](../.github/workflows/ci.yml)) runs the same four as
parallel jobs, plus `tsc --noEmit` and a Vite build.

---

## 2. The pyramid

```
        Browser (Pest v4)    6 critical journeys, only these
      ┌───────────────────┐
      │   Isolation       │  every tenant-scoped endpoint — mandatory
      ├───────────────────┤
      │   Feature         │  every endpoint, every module
      ├───────────────────┤
      │   Unit            │  money · ledger · installment · state machines
      ├───────────────────┤
      │   Arch            │  module boundaries, tenancy traits, strict types
      └───────────────────┘
```

### Arch (`tests/Arch/`)

Boundaries that are only documented get crossed. These assert them:

- Every module directory exists with the conventional layout.
- Every module provider is auto-discovered.
- `Models` / `Services` / `Events` do not depend on `Http` or `Inertia`.
- No `dd`, `dump`, `ray`, `var_dump`, `print_r`, `die` in `app/`.
- `declare(strict_types=1)` everywhere in `app/`.
- *(Phase 1)* every tenant model uses `BelongsToTenant`.

### Unit (`tests/Unit/`, `app/Modules/*/tests/Unit/`)

Boots the application (helpers read config) but **never touches the database**. This
is where the maths that costs real money lives:

- `Money::split()` sums back to exactly the input, remainder on the **last** row.
- `Money::percent()` truncates toward zero — the shop never over-charges by rounding.
- `Money::toToman()` **refuses** a sub-toman remainder instead of rounding.
- `Jalali` shifts UTC into Tehran **before** converting the calendar.
- Installment schedules, cheque status transitions, unit state machines.

### Feature (`tests/Feature/`, `app/Modules/*/tests/Feature/`)

Full application, refreshed database, real HTTP. Every endpoint gets one.

### Isolation (`--group=isolation`)

The suite this product's credibility rests on. Mark a test with the `isolation()`
helper from `tests/Pest.php`.

Minimum shape for every tenant-scoped resource:

```php
it('does not leak tenant A resources to tenant B', function (): void {
    isolation();

    [$a, $b] = Tenant::factory()->count(2)->create();

    $ticket = RepairTicket::factory()->for($a)->create();

    actingAsUserOf($b)
        ->get("/repairs/{$ticket->id}")
        ->assertNotFound();          // 404, never 403 — do not confirm existence
});
```

Plus, once per phase, a **raw-SQL** test that bypasses Eloquent entirely and proves
RLS alone stops the leak:

```php
it('cannot read another tenant rows even without the Eloquent scope', function (): void {
    isolation();

    DB::statement("SET LOCAL app.tenant_id = '{$b->id}'");

    expect(DB::select('select * from repair_tickets'))->toBeEmpty();
});
```

**404, not 403.** A 403 confirms the record exists, which is itself a leak: a
competitor could enumerate invoice ids to size a rival shop's business.

**Once per phase, audit the caches too — RLS does not reach them.** A memo is a read that
never touches Postgres, which is precisely why the guarantee protecting every query does
not protect it. [ADR 0012](adr/0012-tenant-keyed-caches.md): **every singleton with
internal state names the tenant in its key, or says at the key why it does not.**

```bash
grep -rn 'singleton(\|scoped(' app/Modules/*/Providers/*.php app/Providers/*.php
# then, for each class listed: does its cache key start with the tenant id?
php bin/check-forgettable-singletons   # the paired gate — forget() on a non-singleton
```

This is an eyes-on audit rather than a gate because the question is *semantic*: a key can
contain a tenant-unique surrogate and be safe, or contain three ids and still be forgeable.
`PriceResolver` keyed `variant:level:timestamp` — unambiguous-looking, and a leak the moment
the class was shared.

### Browser (Pest v4)

Only six journeys, because browser tests are slow and brittle:

1. Sign up → onboard shop → first login
2. Buy a plan in the payment sandbox → features unlock
3. Receive stock by pasting IMEIs → sell a phone → print
4. Repair intake → status changes → delivery with signature
5. Installment sale → collection → early settlement
6. Public repair tracking page (no login)

---

## 3. Non-negotiables

**Real PostgreSQL, never SQLite.** `phpunit.xml` points at `mobishop_test` on
Postgres. SQLite has no Row-Level Security, so a suite running on it would report
green while proving nothing about the guarantee in [ADR 0002](adr/0002-single-db-tenancy-rls.md).

**The test role is not a superuser.** `mobishop_app` is `NOSUPERUSER NOBYPASSRLS`, and
`enableRls()` emits `FORCE ROW LEVEL SECURITY`, so tests exercise exactly the
enforcement path production traffic takes.

**No network from a test.** SMS driver is `null`, Moadian is `fake`, payments are
faked. A test that reaches a real gateway will pass on your machine and fail at 2am.

**Money assertions are exact.** `toBe(94)`, never `toBeGreaterThan(93)`. Approximate
assertions on money hide exactly the bug they should catch.

**Factories, not fixtures.** Every model gets a factory. The demo tenant seeder builds
a realistic Persian dataset used by both the reconciliation tests and manual demos.

**A multipart form is tested with its optional-array keys absent.** A `FormData` body
cannot express an empty array — an unticked checkbox group is not posted as `[]`, it is
not posted at all. A payload built in PHP always includes the key, so a suite that only
ever constructs its own arrays will never see the shape the browser actually sends.

That gap shipped once: `accessories => ['present', 'array']` on the repair intake
rejected every device handed over without a case or a SIM tray, which is most of them.
Nine passing tests missed it because all nine built the key. The browser found it in
about four seconds.

```php
$payload = intakePayload($branchId);

// What a multipart form actually posts when nobody ticks a box.
unset($payload['accessories'], $payload['checklist']);

$this->actingAs($user)->post($url, $payload)->assertSessionHasNoErrors();
```

**A secret-bearing form is tested on its FAILING submission.** Every form that carries a
secret — a device passcode, a password, a recovery code, a card number — gets a test that
posts a payload which *fails validation*, and asserts the secret appears in none of the
three places a rejected request leaves its input:

1. the session store (`session()->all()`, and the bytes `serialize()` hands the driver),
2. the flashed old input (`session()->getOldInput('…')`),
3. the validation error payload returned to the client.

And the field is named explicitly in `dontFlash` in `bootstrap/app.php`. The framework
default covers `password`, `current_password` and `password_confirmation` and nothing
else; every other secret is opt-in, and the opt-in is invisible until somebody looks.

This is not hypothetical. The repair intake had four layers protecting the customer's
unlock code — encrypted at rest, hidden from serialisation, permission-gated on reveal,
audited on every read — and all four guard the value *after it reaches the model*. A
failed submission never gets that far. Laravel redirects with
`withInput(Arr::except($request->input(), $dontFlash))`, and with `SESSION_DRIVER=database`
and `SESSION_ENCRYPT=false` the code lands in `sessions.payload` in plaintext: in the same
database the encrypted column exists to protect, one table over, visible to any dump,
replica or backup. A photo one megabyte over the limit was enough to trigger it.

Eight passing passcode tests missed it, and they missed it for a structural reason worth
generalising: **every one of them posted an intake that succeeded.** A form that submits
cleanly never flashes old input, so the entire failure path — where a rejected request
puts things it was never asked to keep — had no coverage at all. The same blindness hides
the round-number assumptions in computed figures: a fixture that buys stock at exactly
200,000 never meets the rounding guard that real weighted-average cost trips on the first
search.

Test the path that fails, not only the path that works.

```php
it('does not flash the passcode when the intake fails validation', function (): void {
    $response = $this->actingAs($user)->post($url, [
        ...$payload,
        'device_passcode' => SECRET,
        'device_model' => '',   // the trigger, not the point
    ]);

    $response->assertSessionHasErrors('device_model');

    // The form comes back populated — that is what old input is for...
    expect(session()->getOldInput('device_brand'))->toBe('اپل');

    // ...but not with this field, and not in the bytes a driver would persist.
    expect(session()->getOldInput('device_passcode'))->toBeNull();
    expect(serialize(session()->all()))->not->toContain(SECRET);
});
```

**Before pinning a test, ask: can this assertion fail if the code is wrong?** A test that
cannot fail is worse than a missing one — it occupies the slot where the real test would
go, and it reports green forever.

The trap is easy to walk into while writing something that looks careful. From the
instalment maths:

```php
// Asserts nothing. True for every possible value of either side.
expect($quote['rebate'])->toBe($quote['profit_due'] + $quote['rebate'] - $quote['profit_due']);
```

That passed on the first run, which is exactly why it survived review — a red test gets
read, a green one gets trusted. It was replaced with the claim it was supposed to make: the
rebate shrinks as the term elapses, 12,000,000 back with nothing paid and 333,330 back with
five of six instalments paid.

Two habits catch these. Read the assertion with the implementation deleted and ask what
would still hold; and where a figure is asserted, make sure it was computed by different
means than the code under test — a test that reruns the implementation's own arithmetic
proves only that the code is deterministic.

**That habit's first kill, one phase later.** `MessagingTenantIsolationTest` reported green
while processing no jobs at all: the job queues itself on `sms` and the worker was draining
`default`, so nothing ran — and its cross-tenant assertion, `expect(Message::count())->toBe(0)`
for the other shop, was true *precisely because* no message existed anywhere. Reading the
assertion with the implementation deleted is what exposed it. The fix was to assert the
message DOES exist for the shop that sent it, before asserting it does not for the shop that
did not: **a negative assertion needs a positive one beside it, or it passes on an empty
world.**

### Green without witness

The generalisation of that kill, and the name to use for it in review: **a test is green
without witness when its fixture does not contain the thing it claims to measure.** The
arithmetic is never exercised, both sides collapse to the same empty value, and the
assertion passes — permanently, and for a reason that has nothing to do with the code
under test.

It is the golden-number tests that are most exposed, because their whole shape invites it:
seed a scenario, run a report, compare to a figure written by hand. If the scenario is
missing the subject, `0 === 0` and the report is "verified".

`GoldenNumbersTest` pinned sales revenue and sales profit against `CrazyMonthSeeder` — the
Phase 7 "one crazy month" that the entire reporting phase reconciles to — and the seeder
**contains no sales invoices at all.** Two assertions, green since Phase 7, proving that
zero equals zero. The report they were guarding could have returned any number for any
month without either of them noticing.

So, before a golden number is pinned:

1. **Assert the fixture contains the subject, in the same test**, above the arithmetic —
   `expect(Invoice::where(...)->count())->toBeGreaterThan(0)` earns the figures below it.
2. **Or assert the emptiness explicitly**, naming it as the claim: if the scenario really
   has no sales, say `toBe(0)` *because there are no sales*, with a comment pointing at
   the fixture that would have to change and the test that pins the arithmetic instead.
   That is what those two assertions became, and they now point a future reader at
   `SalesReportScreenTest`, which pins 290,000,000 revenue · 180,000,000 cost ·
   110,000,000 profit against a fixture built to contain them.

Never the third option — an exact figure asserted against a fixture nobody checked. The
figure looks like evidence and is decoration.

This is the same defect as the empty-world negative above, and the same defect as the
`x + y - y` tautology: an assertion that cannot distinguish a working implementation from
a broken one. The tell is always available and always cheap — **read the assertion with
the implementation deleted, and then read it again with the fixture emptied.** A test that
survives both is not a test.

#### Money fixtures use non-round amounts by default

The same defect wearing its most common disguise. A fixture can be full — invoices,
lines, movements, all present — and still be **without witness for the arithmetic**,
because every amount in it divides evenly. Round numbers do not exercise remainders, and
remainders are where money code breaks.

This file predicted the exact case in writing, two sections above: *"a fixture that buys
stock at exactly 200,000 never meets the rounding guard that real weighted-average cost
trips on the first search."* It then happened, and it is worth naming precisely because
the prediction was not enough to prevent it — only a fixture rule is.

**`StockLedger::weightedAverageCost()`** divides total value by total quantity. Every
seeder and every test bought stock at round prices, so the division always landed on a
whole toman and the result was always renderable. `Money::toToman()` *refuses* a sub-toman
remainder rather than rounding it, so the first real shop to buy a hundred chargers at
50,000 and ten at 90,000 gets an average of **53,636 rial** — 5,363.6 toman — and the
sales report they open every morning throws instead of rendering. The whole suite was
green. The guard that would have fired had never been handed a number that could fire it.

So:

1. **A money fixture's default amounts are non-round** — prices that do not divide by
   the quantities bought, totals that do not land on the rounding step. `50_000` and
   `90_000` over 100 and 10 units is the canonical shape: it is realistic, and it
   produces a remainder.
2. **A seeder helper that cannot produce a round toman is worth more than a hundred
   round-number fixtures.** Prefer generating amounts from a rule that guarantees a
   remainder over hand-picking values a later editor will "tidy" back to 200,000.
3. Keep round numbers only where the *roundness itself* is the claim — an on-the-step
   total that must not move under `up` rounding, for instance ([ADR 0009](adr/0009-invoice-rounding.md)).

The general form: **any figure the code divides, allocates or rounds needs a fixture
whose inputs do not divide evenly.** Discount allocation across lines, per-line VAT,
instalment splits, landed-cost allocation and weighted-average cost are all the same
shape, and all five are green-without-witness against tidy inputs.

**Where a rounding rule exists, pin the figure the *wrong* implementation would give,
beside the right one.** A correct figure on its own says the code produced *a* number; the
pair says it produced *this* one rather than the plausible alternative. The strongest form
is one assertion of each:

```php
// ADR 0009 floors per LINE, so two lines floor twice.
expect($totals['vat']['value'])->toBe(1_776_380);

// And explicitly NOT what a period-level recompute gives. Eighteen rial is small;
// being eighteen rial away from the invoices is not.
expect($totals['vat']['value'])->not->toBe(intdiv(17_763_980 * 10, 100));
```

That is the VAT summary, and the gap only exists because the fixture prices every line at
8,881,990 — a whole toman a shop can charge, whose 10% is not one. Against round prices both
implementations return the same number, the `not->toBe` passes for the wrong reason, and a
report that disagrees with every invoice it summarises ships green. **The negative assertion
is only worth writing when the fixture can tell the two apart** — which is the same rule as
the paragraph above, arriving from the other side.

#### A feature with enforcement but no write path is invisible

The sibling of the silently-passing guard, and it hides for the same reason: **nothing
fails.** Where that one is a check that always answers yes, this is a check that is
never asked — because the state it reads can never be set.

`branch_user` was created in Phase 2. `BranchAccess` read it correctly. Sales, Repairs and
Inventory enforced it, with tests. And **no screen, route or service ever wrote a row to
it** — there was no branch-management page, no assignment control, no way to create a
second branch. So every user was unrestricted, every query returned everything, and every
screen looked right for eight phases.

Which meant the five modules with *no branch filter at all* were indistinguishable from the
three that had one. The bug could not be observed until the moment the feature became
reachable, and it became reachable in the same commit that would have introduced it if it
had not already been there.

The audit question, and it generalises past this case:

> **For every table whose contents are enforced, ask who writes to it — and reach that
> writer from a screen.** If the answer is "a factory, a seeder, or Tinker", the enforcement
> has no coverage in the only sense that matters, and the tests around it are describing a
> state the product cannot enter.

The tell is cheap and mechanical. Grep for writes:

```bash
# Who inserts into branch_user? (BranchAccess reads it; a test factory is not an answer.)
rg -n "branch_user|branches\(\)->(sync|attach)" app/ database/ --glob '!*/tests/*'
```

Empty output next to a table that policies, scopes or middleware read is the finding. The
same shape applies to any permission pivot, feature-flag override table, or settings row a
guard consults: **enforcement without a reachable writer is a guarantee about a state
nobody can reach**, and it reports green forever.

Related failure, one step further along: a feature whose write path exists but whose *only*
fixture writes the permissive value. See "the fixture talking, not the query" under §6 —
seeded data that populates one side of a conditional leaves the other branch unexercised,
and the measurement or assertion covering it is describing nothing.

#### Assert distinguishability, not predicted labels

A named pattern, and the reason the Storefront render pass catches a bug its author could
not have anticipated.

> **Whoever could predict the exact expected label is whoever would not have written the
> bug.** So do not assert the label. Assert the *property* that distinguishes correct output
> from broken output.

Four variants of one handset — same product, same brand, differing only in a colour/storage
`options` map — shipped rendering as four identical rows at four different prices, because
the catalogue query selected `product_variants.name` (null for anything matrix-generated)
and never looked at `options`. It reads as a pricing error rather than a product range.

A literal assertion would not have caught it:

```php
// Useless here. Written by somebody who already knows the label format —
// which is exactly the person who would have selected `options` in the first place.
->assertSee('آیفون ۱۵ پرو مکس — تیتانیوم مشکی · ۵۱۲')
```

The structural one needs no such knowledge:

```php
// True of ANY correct catalogue. False of the bug. Knows nothing about label format.
$labels = renderedRowLabels($response->getContent());

expect($labels)->toHaveCount(4);
expect(array_unique($labels))->toHaveCount(4);
```

Verified by reintroducing the defect: dropping `options` from the select produces
`Failed asserting that actual size 1 matches expected size 4` — on the shop page, the
reseller list *and* the print sheet.

**The shapes this generalises to.** Each asserts a property rather than a value, so each
catches a class of regressions rather than one known string:

| claim | assert |
|---|---|
| N distinct inputs are distinguishable in the output | `count(array_unique($rendered)) === N` |
| a formatter ran at all | the *raw* value does not appear — `not->toContain('88819990')` |
| a date went through the Jalali helper | no `\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}` anywhere in the page |
| a template rendered | no `{{`, `@if`, `>Array<` in the output |
| a total is derived, not typed | the parts sum to the whole (§5's conservation claims) |

The date row is the one to notice: it says *no machine timestamp reaches a visitor*, which is
true of every correct page in this product and false of any broken date path. Reintroducing
the shadowed `jdate()` fails it — **seven phases earlier than Blade actually found it.**

Pair each with a positive assertion, per §3: "no raw timestamp" passes trivially on a page
showing no date at all, so `assertSee('۱۴۰۵/۰۶/۱۰')` sits beside it.

#### Three defects that a gate now catches, and the question behind each

The pattern above has a family, and it kept costing. Each of these shipped, each was found
by accident rather than by a test, and each has the same tell: **nothing crashes — the
wrong thing silently wins a name, a call, or a placement.** All three now fail the build
(`composer guards`, and three steps in CI), because a rule that lives only in prose is a
rule somebody re-derives at 2am.

The audit question and the grep are given for each, because the gate catches the *shape*
and the question catches the *variant the gate has not learned yet*.

**1 — Is the `try` outside the `DB::transaction()`?**

An idempotent insert that catches 23505 must run in a nested transaction, and the `try`
must sit **outside** it. `DB::transaction()` releases its SAVEPOINT when the closure
*throws*; a closure that swallows its own exception never triggers that, so the recovery
query runs on a still-aborted connection and dies with `25P02`.

Got wrong three times — `AbandonedSweep::insertOnce()`, `SendSms::record()`,
`SubmitInvoice::enqueue()`. Twice it presented as **a cascade of unrelated tests failing**
after the one that actually collided, which is the most expensive way to find anything.

```bash
# Every recovery site with its surrounding lines, so you can eyeball the nesting:
grep -rn -B6 '23505' app/ --include='*.php'
php bin/check-savepoint-recovery      # the gate
```

**2 — Does any vendor package define this global helper already?**

`function_exists`-guarded helpers are first-writer-wins, and Composer loads vendor `files`
**before** the application's. `App\Support\helpers.php` defined `jdate()`; so does
morilog/jalali. Ours was dead for eight phases and *looked* live — it returned the
package's format, with a time on it, where every screen renders `۱۴۰۵/۰۶/۰۲`. Nothing
called it until a Blade view did.

```bash
# vendor/composer/autoload_files.php IS the authoritative list of globally-loaded files —
# every package that ships helpers is in it, so there is nothing to guess or maintain:
php -r 'foreach (require "vendor/composer/autoload_files.php" as $f) echo $f, "\n";' \
  | xargs grep -hEo '^ *function [a-z_]+' 2>/dev/null | sort -u
php bin/check-global-helpers          # the gate
```

**3 — Is this `forget()` clearing anything at all?**

`app(Foo::class)->forget()` on a class that is not bound `singleton()` clears a **brand-new
instance's empty cache** while the one the caller holds keeps answering stale.
`BranchAccess` was the first found; the gate then turned up **87 more no-op calls** across
`SubscriptionResolver` and `PriceResolver`.

```bash
# Which classes are forgotten through the container…
grep -rEno 'app\([A-Za-z]+::class\)->forget\(' app/ tests/ | sed 's/.*app(/app(/' | sort -u

# …and which are actually shared. Anything in the first list and not the second is a no-op.
grep -rn 'singleton(' app/Modules/*/Providers/*.php app/Providers/*.php

php bin/check-forgettable-singletons  # the gate
```

**And the sting in the tail of #3.** Binding a memoising class as a singleton is not free:
it turns per-instance state into **shared** state. `PriceResolver` keyed its cache
`variant:level:timestamp` — sufficient while every resolution was a fresh instance, and a
**cross-tenant leak** the moment it is shared, because a crafted request can pass another
shop's variant and level ids and read back a price RLS would have refused. Three ordinary
situations serve two tenants from one container: a queued job, a test's `runFor()`, and the
storefront resolving a price-list token mid-request.

So the follow-up question, every time: **what is in the cache key, and is the tenant in
it?** A leak introduced by a performance optimisation is the worst kind to go looking for,
because nothing about the optimisation looks like access control.

`PriceResolverSharingTest` pins it — and took **three attempts to become capable of
failing**, which is the §3 discipline arriving in person: first the instant defaulted to
`now()` so the two calls never shared a key; then the price level defaulted so each shop
resolved its own; only when both ids were passed explicitly did removing the tenant from
the key produce `Failed asserting that 88819990 is null`. Two green runs before that, in a
file written specifically to catch a leak.

**A gate that reports ten non-bugs dies socially before it dies technically.** Nobody
deletes a noisy check; they comment out the CI step "just for this PR", and it never comes
back. So the false-positive rate is not a polish concern — it is the gate's survival
condition, and it is paid for at the time of writing or not at all.

Two things buy it, and the unique-index check in `tenancy:check` needed both:

- **Resolve transitive scoping before reporting.** A unique index leading with a foreign
  key to a tenant-owned row is already scoped — the parent carries `tenant_id`, so two
  shops cannot collide through it. A gate that only looks for the literal column reports
  every one of those as a finding. Following the foreign key took the report from ten
  entries to zero on a clean schema, which is the difference between a check somebody runs
  and a check somebody silences.
- **Allow-list deliberate exceptions with a reason each, in the source.** Four indexes here
  are genuinely global and must stay that way — two bearer credentials, one gateway-issued
  id, one public URL segment. Written as bare names they are indistinguishable from
  oversights the next reader will "fix"; written with the reason beside them they are a
  decision the gate is enforcing rather than a hole in it.

And prove the gate can fail, per §3: the unique-index check was verified by planting an
unscoped index on `parties.national_id` and watching it get reported. A gate that has only
ever printed zero findings has not been shown to have eyes.

**A number that is silently wrong beats a number that is missing, every time — so parse, never strip.** `PartyImporter` normalised money by stripping every non-digit and casting to
`int`. An Iranian sheet writes a decimal with a **slash**, so `12500000/0` toman became
`125000000` toman — **ten times** the balance — and `12500000.00` became a hundred times
it. Nothing threw. Nothing logged. The customer simply owed ten times what they owed, and
the ledger built on it from there.

It was found by probing the reader layer before designing the products import, not by a
test, and the tell is the usual one: **the wrong value is a perfectly plausible value.**
`1,250,000,000` looks like money. No assertion about "the import succeeded" can see it.

Two rules came out of it:

- **One money parser, and it refuses rather than guesses.** `Money::parse()` already
  existed and already threw on a stray character; the importer had rolled its own instead.
  A second implementation of a rule is a second opinion about it, and the one that gets
  used is whichever the author remembered.
- **An unreadable money cell is a row error, never a zero.** Importing `0` for a cell
  nobody could parse is the same failure one step later — it lands in a balance, which is
  the last place anyone looks.

Verified by planting the old parser back and watching the suite go red with
`Failed asserting that 1250000000 is identical to 125000000`. A regression test written
against a bug you cannot re-introduce on demand has not been shown to test anything.

**A dry run is only trustworthy if it is the import, stopped.** Both importers in this
codebase share one walk: `analyse()` and `import()` run **identical** code and only the
second commits. The alternative — a summary built by separate logic — is a screen that
reports one outcome and performs another, and the shop finds out weeks later.

That property is worth a test of its own, and it is cheap: assert the dry run wrote
nothing, then assert the committed counts equal the dry-run counts for the same file.

**The products import found three defects on the browser walk that the feature tests could
not.** All sixteen were green first. Worth listing, because the pattern in what a
server-side test cannot see is consistent:

| defect | why no PHP test saw it |
|---|---|
| `<Money value={…}>` instead of `rial={…}` | the payload was correct JSON; only *rendering* it threw |
| verdict messages clipped out of their cell | no assertion in a feature test has a width |
| a ragged CSV row silently importing a price of `18` | the fixture was **built in PHP**, so it could not contain the malformed row a real file has |

The third is the one to remember, and it is §3's *green without witness* in a new costume:
**a hand-built fixture cannot express the malformation you are trying to survive.** Every
CSV a PHP test writes is well-formed, because the test writes it field by field. The file
a shop sends has an unquoted comma inside a product name — and then every column after it
shifts by one, the price column reads `18` instead of `18,900,000`, and the row imports as
a phone costing eighteen toman. Nothing is empty. Nothing throws. It is a plausible number
in the right column.

The guard is a comparison the file can lose against: a data row with **more** fields than
the header is malformed, and is refused with both counts named. Fewer is fine — a trailing
empty column is routinely omitted and shifts nothing. And the general rule: when a fixture
is generated by the same language that parses it, ask what a *human's* file contains that
yours structurally cannot.

**A harness bug reads exactly like a domain bug — instrument before hypothesising.** When a
test fails, the fault is as likely to be in the scaffolding as in the code, and the two are
indistinguishable from the failure message. Three tenant-isolation tests failed with "no
message found", which reads as a tenancy leak; the cause was that `queue:work` defaults to a
**128 MB memory ceiling and quits after the current job once the process exceeds it**. Run
alone the file was under the limit and passed; run inside the full suite the process was
already past it before the first job, so the worker handled exactly one job per call.

Two wrong hypotheses were tried before that — a cached queue connection, then a config
ordering problem — and both produced plausible fixes that changed nothing. What found it
was printing the queue depth before and after the drain: `queued=4 left=3`. One line of
instrumentation beat two rounds of reasoning about a system whose behaviour differed
between runs.

The rule: when a test's failure does not match what you changed, **measure the harness
before theorising about the domain.** A test that fails only in the full suite, or only in
CI, or only after another file has run, is a harness suspect until proven otherwise.

**Prefer an invariant to a hand-maintained figure, wherever both express the claim.** An
exact-number assertion has to be updated by a person every time the scenario grows, and a
person updating it can update it wrongly — at which point the test still passes and the
property it guarded is gone.

The Phase 7 reconciliation harness was first written as exact monthly totals: cash held,
total spent. Every slice of the seeded month broke it, and each break was a judgement call
about whether the new number was right. The version that replaced it never needs editing:

```php
// Every ledger row names one subject and carries one of debit or credit, and every batch
// balances — so across all subjects the movements cancel, and what is left is what the
// shop opened with. Whatever happened in between.
expect($totalBalances)->toBe($totalOpenings);
```

Conservation claims — nothing created or destroyed, parts summing to the whole, a balance
equalling the entries beneath it — survive scenario growth because they describe a property
rather than a state. Keep exact figures beside them for the specific things a slice
introduced; just do not let them be what "reconciles" means.

**Client-side file handling is never covered by server-side tests alone.** Camera
capture, file pickers, drag-and-drop and paste-to-upload all live in a browser handler
that no PHP test executes. A feature test posting `UploadedFile::fake()` proves the
*server* stores what it is given; it says nothing about whether the browser ever put the
file in the body. Those are two different bugs, and only one of them has coverage by
default.

So any screen that attaches a file gets a browser assertion that **the request payload
actually contained it** — not that a button exists, not that a preview rendered, but that
the bytes were in the multipart body. Where a browser test is not yet available, the
minimum is a manual walk recorded in `docs/walks/`, and the box does not tick until
somebody has watched a real file arrive.

The repair intake shipped this bug with a fully green suite:

```tsx
// Broken. `Array.from(e.target.files)` is inside a callback React runs LATER —
// by which time the next line has cleared `value`, and clearing `value` empties `files`.
setPhotos((current) => [...current, ...Array.from(e.target.files ?? [])]);
e.target.value = '';

// Correct. Read the files now; queue the state update with what you already hold.
const picked = Array.from(e.target.files ?? []);
setPhotos((current) => [...current, ...picked]);
e.target.value = '';
```

Every intake posted with **zero photos**. No thumbnail, no error, nothing in the log —
and photos are the intake screen's whole reason for existing. Three weeks later, when the
customer insists the back glass was fine when they handed the phone over, the shop has a
checklist that says «خط و خش» and no picture to put next to it.

The tests that should have caught it could not: they build `UploadedFile` arrays in PHP
and hand them straight to the endpoint, which is precisely the step that was broken. It
was found by walking the screen.

**A form has somewhere to show an error that belongs to no field.** The companion to the
rule above, and the reason that bug was invisible rather than merely wrong: the intake
page rendered errors only beside `device_model` and `reported_issue`, so a failure on
`accessories` redirected back and changed nothing on screen. Assert the general region
exists, and assert a field-less error reaches it.

---

## 4. Coverage targets

| Area | Target | Why |
|---|---|---|
| Money, ledgers, stock, installments, cheques | ≥ 85% | A wrong number here is a legal problem |
| Tenant-scoped endpoints (isolation) | 100% | No exceptions |
| Repairs state machine | 100% of transitions, legal and illegal | The flagship module |
| UI components | Gallery + browser journeys | Visual review is the gallery's job |

Coverage is a floor, not a goal. A module at 90% with no isolation test is failing.

---

## 5. Reconciliation tests

From Phase 7 onward, one seeded scenario ("one crazy month") is the source of truth:
purchases, sales with trade-ins, repairs consuming parts, cheques through their full
lifecycle, installments collected and settled early, expenses and rentals.

Every report asserts **exact expected figures** against it, and the P&L must
reconcile to the rial. When a number disagrees, the ledger is right and the report is
wrong — that is the whole point of never storing totals
([ADR 0003](adr/0003-modular-monolith.md)).

---

## 6. Performance budget (from Phase 9)

Seeded with 100k rows, the top reports must return in **< 300ms**. Asserted in CI, so
a missing index is a failing test rather than a support ticket.

The fixture is [`BulkVolumeSeeder`](../database/seeders/BulkVolumeSeeder.php) and the
assertion is `ReportLatencyTest`. One shop gets a year of trading — 40,000 invoices,
100,000 invoice lines, ~100,000 stock movements, ~75,000 ledger rows — and every value
is a deterministic function of the row's ordinal, so the same seed produces the same
rows and the same plan on every run.

Five rules that fixture had to learn, none of them obvious from the outside:

**The neighbour is the same size.** On a single-tenant table a sequential scan and an
index scan do identical work, so a budget measured there passes with every index
dropped. Both shops are filled, the table holds twice what any report reads, and the
tenant predicate has to earn its place in the plan.

**The fixture talking, not the query.** A latency figure is only comparative when **both
directions of the data exist**. The seeded ledger wrote debits only — every party owed, no
party had paid — so `settled` was always zero, the aging report's FIFO clamp
`least(lot, greatest(cumulative − settled, 0))` collapsed to `lot` on every row, and the
expensive branch never ran at all. The payable direction, which reads the credits, was
timing an empty set at full speed.

Nothing failed. Both numbers were comfortably inside the budget, and the report was
"measured". What gave it away was reading the two figures *against each other*: **84.8ms
receivable against 20.5ms payable**, a four-fold gap between two directions of one query
over one table. A query does not get four times faster because you swapped which column it
sums; that gap was the fixture. Part-payments against every third invoice fixed it, and the
payable figure moved to 29.6ms — still lower, now for the honest reason that a shop has
fewer payables than receivables.

So: **when two measurements should be comparable, compare them, and treat an unexplained
gap as a missing-data suspect before a performance finding.** The same shape appears wherever
a fixture populates one side of a conditional — an empty `product_units` table making a
valuation look fast, a status column with one value making a `filter (where …)` free. The
budget passing is not evidence the branch ran.

**ANALYZE after every step, not once at the end.** Postgres plans from statistics and a
bulk-loaded table has none; autovacuum cannot help, because the rows are uncommitted and
under `RefreshDatabase` never commit at all. That costs twice. The reports would be timed
against a planner that thinks every table is empty — a sequential scan over twelve
imagined rows is chosen instantly, so the budget passes while proving nothing. And the
seeder's own later statements plan badly: the sale-movement insert, joining 100,000
unanalysed lines to 40,000 unanalysed invoices, was **still running after seven minutes**
against 1.4 seconds once the two tables had statistics.

**It is a fixture for timings, never for figures.** Every amount in it is arithmetic the
seeder invented. A report pinned to it would be pinned to that invention — §3's *green
without witness*, one step worse, because the table is full and the number still means
nothing. Money is pinned in `SalesReportScreenTest` and `GoldenNumbersTest`, against
scenarios the real services built.

**The budget is a ceiling, not a regression detector.** Measured figures at the time of
writing are 1–93ms against 300ms across 26 measurements, so a change making a report three
times slower still passes. Tightening it to close that gap buys a test that fails on a busy CI box and
teaches everyone to re-run it. The detector for a *plan* change is the fixture itself:

```bash
php artisan db:seed --class=Database\\Seeders\\BulkVolumeSeeder
# then EXPLAIN (ANALYZE, BUFFERS) the report's query and read what the scan is
# proportional to — the range asked for, or everything the shop has ever sold?
```

That is how the missing index on `sales_invoices` was found: a thirty-day report was
reading 75,200 index entries and 12,533 heap rows to keep 3,093, because
`(tenant_id, status)` stops before the date and `(tenant_id, branch_id, issued_at)`
cannot be entered without a branch. The cost grew with the shop's whole history rather
than with the range — invisible at eleven demo invoices, and a complaint from the biggest
customer eighteen months in.

---

## 7. Writing a test — checklist

1. Feature test for the happy path.
2. Feature test for each validation failure the user can actually cause.
3. Isolation test — tenant B gets 404 on tenant A's resource.
4. Unit tests for any money, date or state-machine logic involved.
5. Assert the ledger/movement rows, not just the HTTP status: a green 200 that wrote
   nothing is the failure mode that matters here.
