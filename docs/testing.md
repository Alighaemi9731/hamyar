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

---

## 7. Writing a test — checklist

1. Feature test for the happy path.
2. Feature test for each validation failure the user can actually cause.
3. Isolation test — tenant B gets 404 on tenant A's resource.
4. Unit tests for any money, date or state-machine logic involved.
5. Assert the ledger/movement rows, not just the HTTP status: a green 200 that wrote
   nothing is the failure mode that matters here.
