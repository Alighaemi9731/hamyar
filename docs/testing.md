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

Four rules that fixture had to learn, none of them obvious from the outside:

**The neighbour is the same size.** On a single-tenant table a sequential scan and an
index scan do identical work, so a budget measured there passes with every index
dropped. Both shops are filled, the table holds twice what any report reads, and the
tenant predicate has to earn its place in the plan.

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
writing are 1–46ms against 300ms, so a change making a report three times slower still
passes. Tightening it to close that gap buys a test that fails on a busy CI box and
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
