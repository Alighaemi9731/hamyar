<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Services\DeliverTicket;
use App\Modules\Repairs\Services\QuoteApproval;
use App\Modules\Repairs\Services\TicketIntake;
use App\Modules\Repairs\Services\TicketStateMachine;
use App\Modules\Repairs\Services\TrackingLink;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;

/**
 * The public tracking page, treated as hostile.
 *
 * The threat is worse here than on the public invoice: an invoice tells a stranger what
 * somebody bought, while a repair status tells them a named person's device is **out of
 * their hands right now**. Every test below is about what a visitor with a URL can learn.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');

    // A cap high enough that these tickets never need approval. The cap fails CLOSED —
    // unset means everything needs a customer's yes — so without this the fixtures
    // cannot leave `diagnosing`, which is the guard doing exactly its job.
    $this->tenant->forceFill(['settings' => ['repairs' => ['approval_cap' => 999_999_999]]])->save();

    app(SubscriptionResolver::class)->forget();
    app(TenantContext::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return [$owner, Warehouse::factory()->create()];
    });

    [$this->owner, $this->warehouse] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

function trackedTicket(bool $withParty = false): RepairTicket
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var User $owner */
    $owner = test()->owner;

    /** @var RepairTicket $ticket */
    $ticket = inTenantContext($tenant, fn (): RepairTicket => app(TicketIntake::class)->take([
        'branch_id' => $warehouse->branch_id,
        // Credit has to be owed by somebody: `FinaliseInvoice` refuses an unsettled
        // invoice with no party, which is the right guard and not this test's subject.
        'party_id' => $withParty ? Party::factory()->create()->id : null,
        'device_brand' => 'اپل',
        'device_model' => 'آیفون ۱۳',
        'device_imei' => '356938035643809',
        'reported_issue' => 'روشن نمی‌شود',
        'device_passcode' => '4517',
        'estimate_amount' => 3_000_000,
    ], $owner->id));

    return $ticket;
}

/* -------------------------------------------------------- it works -- */

it('opens for a customer with no account', function (): void {
    $ticket = trackedTicket();

    $link = ($this->inTenant)(fn () => app(TrackingLink::class)->for($ticket));

    $this->get((string) $link)
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Repairs::Tracking/Show')
            ->where('ticket.code', $ticket->code)
            ->where('ticket.status', TicketStatus::Queued->value)
        );
});

it('shows the customer the status changing', function (): void {
    $ticket = trackedTicket();

    ($this->inTenant)(function () use ($ticket): void {
        app(TicketStateMachine::class)->transition($ticket, TicketStatus::Diagnosing, $this->owner->id);
        app(TicketStateMachine::class)->transition($ticket, TicketStatus::Ready, $this->owner->id, 'تمام شد');
    });

    $link = ($this->inTenant)(fn () => app(TrackingLink::class)->for($ticket));

    $this->get((string) $link)
        ->assertInertia(fn ($page) => $page
            ->where('ticket.is_ready', true)
            // Intake + two moves.
            ->has('ticket.timeline', 3)
        );
});

/* ------------------------------------------------- nothing to enumerate -- */

it('puts no id in the tracking url at all', function (): void {
    $ticket = trackedTicket();

    $link = (string) ($this->inTenant)(fn () => app(TrackingLink::class)->for($ticket));

    $path = (string) parse_url($link, PHP_URL_PATH);
    $query = parse_url($link, PHP_URL_QUERY);

    // The correction of the invoice page's oracle, asserted on the SHAPE of the path
    // rather than on substrings — a ticket id of `3` occurs by chance inside a
    // 48-character random token, so "does not contain the id" is not the property that
    // matters. What matters is that the path is one opaque segment and nothing else:
    // no sequential id to increment, and no signature parameter implying one exists.
    expect($path)->toBe('/t/'.$ticket->tracking_token)
        ->and($path)->toMatch('#^/t/[A-Za-z0-9]{48}$#')
        ->and($query)->toBeNull()
        ->and($link)->not->toContain($ticket->code);
});

it('answers a wrong token exactly like a missing one', function (): void {
    trackedTicket();

    $wrong = str_repeat('a', 48);
    $short = 'abc';

    // Identical responses. A repair status reveals that a named person's device is out
    // of their hands, so there must be nothing to learn from probing.
    $this->get($this->url.'/t/'.$wrong)->assertNotFound();
    $this->get($this->url.'/t/'.$short)->assertNotFound();
});

it('will not accept the human-readable code as a credential', function (): void {
    $ticket = trackedTicket();

    // `REP-000001` is printed on paper and read out over the phone precisely because it
    // is short and human — which is exactly what makes it unfit to be a credential.
    $this->get($this->url.'/t/'.$ticket->code)->assertNotFound();
});

/* ------------------------------------------------- what it will not say -- */

it('tells a visitor nothing about the device, the customer or the shop internals', function (): void {
    $ticket = trackedTicket();

    ($this->inTenant)(function () use ($ticket): void {
        app(TicketStateMachine::class)->transition(
            $ticket,
            TicketStatus::Diagnosing,
            $this->owner->id,
            // The kind of thing staff genuinely write in a note field.
            'مشتری سخت‌گیر است، قیمت را بالا بده',
        );
    });

    $link = (string) ($this->inTenant)(fn () => app(TrackingLink::class)->for($ticket));

    $response = $this->get($link);

    /** @var array<string, mixed> $props */
    $props = $response->viewData('page')['props'];

    $payload = json_encode($props, JSON_UNESCAPED_UNICODE);

    expect($payload)
        // The serial a stolen-device registry check keys on.
        ->not->toContain('356938035643809')
        // The unlock code, and any hint one exists.
        ->not->toContain('4517')
        ->not->toContain('passcode')
        // Internal notes. This one would end a customer relationship.
        ->not->toContain('سخت‌گیر')
        ->not->toContain('technician');

    // The token is NOT asserted absent, and that is deliberate rather than an oversight.
    // It is the address the visitor is already at — it is in their URL bar, their
    // history and any screenshot of the window. Claiming otherwise would be asserting
    // something false about how the web works.
    //
    // What follows from that, and belongs on the Phase 11 checklist: this URL must never
    // be sent anywhere it could be recorded by a third party. There is no external
    // script on this page today; when error reporting is wired, `/t/*` needs its path
    // scrubbed before anything leaves the server.
    expect($props)->toHaveKey('ticket');
});

it('shows no staff-only shared props to a stranger', function (): void {
    $ticket = trackedTicket();

    $link = (string) ($this->inTenant)(fn () => app(TrackingLink::class)->for($ticket));

    // Genuinely anonymous — `trackedTicket()` ran as the owner.
    auth()->logout();

    $response = $this->get($link);

    /** @var array<string, mixed> $props */
    $props = $response->viewData('page')['props'];

    /** @var array<string, mixed> $auth */
    $auth = $props['auth'];

    // The gate added when the public invoice page was found leaking these.
    expect($auth['user'])->toBeNull()
        ->and($props['announcements'])->toBe([])
        ->and($props['features'])->toBe([]);
});

/* ------------------------------------------------------------ isolation -- */

/*
| This test was rewritten rather than moved, because ADR 0017 changed what there is to
| prove.
|
| It used to send shop A's token to shop B's hostname and demand a 404: the host chose
| the shop, RLS confined the lookup to it, and A's row was simply invisible from B's
| address. There are no per-shop addresses now. `app.<apex>/t/{token}` is the only
| tracking URL there is, so "a live token 404s on this host" no longer describes an
| attack — it describes the page being broken, and the assertion would have gone on
| passing while describing it.
|
| The guarantee that replaced it: the token is globally unique, it names exactly one
| ticket in exactly one shop, and it is what pins the context. So the property worth
| asserting is that two shops' links — identical in shape, served by the same host —
| each open their own ticket and never the other's.
*/

it('opens the shop the token belongs to, and never the other shop on the same address', function (): void {
    $mine = trackedTicket();

    $other = Tenant::factory()->withDomain()->create();

    /** @var RepairTicket $theirs */
    $theirs = inTenantContext($other, fn (): RepairTicket => RepairTicket::factory()->create([
        'device_brand' => 'سامسونگ',
        'device_model' => 'گلکسی S23',
    ]));

    $this->get(appUrl().'/t/'.$mine->tracking_token)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ticket.code', $mine->code)
            ->where('ticket.device', 'اپل آیفون ۱۳')
            // The shop the visitor is told they are dealing with is the token's shop.
            ->where('tenant.name', $this->tenant->name)
        );

    $this->get(appUrl().'/t/'.$theirs->tracking_token)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ticket.device', 'سامسونگ گلکسی S23')
            ->where('tenant.name', $other->name)
        );

    // And a session gets no vote here. Somebody signed in at the other shop, opening a
    // link a friend forwarded them, still sees the ticket the token names: these pages
    // are pinned by ResolvePublicTenant, never by whoever happens to be logged in — the
    // one place in the application where the session is deliberately not the authority.
    actingForTenant($other)->get(appUrl().'/t/'.$mine->tracking_token)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ticket.code', $mine->code)
            ->where('tenant.name', $this->tenant->name)
        );
})->group('isolation');

it('gives every ticket a different token', function (): void {
    $first = trackedTicket();
    $second = trackedTicket();

    expect($first->tracking_token)->not->toBe($second->tracking_token)
        ->and(strlen($first->tracking_token))->toBe(48);
});

/* ------------------------------------ what the customer owes, honestly -- */

/*
| The page told people they owed money they had already paid.
|
| `amount_due` was approved-minus-prepaid, always — including after delivery, because a
| ticket had no way to find the invoice that settled it. A customer who paid in full and
| walked out with their phone could open the link that evening and read a four-figure
| balance. Nothing was wrong with the money; the page was simply reciting an estimate
| that had stopped being the truth an hour earlier.
|
| Found by walking the DoD, not by a test. Every test above asserts on the ticket, and
| the delivery tests assert on the invoice. None of them asked what the customer sees
| once both exist.
*/

it('shows nothing owing once the repair has been paid for', function (): void {
    $ticket = trackedTicket();

    ($this->inTenant)(function () use ($ticket): void {
        $warehouse = Warehouse::query()->where('is_sellable', true)->firstOr(
            fn (): Warehouse => Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true])
        );

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        $cashId = (int) $cash->id;
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        $machine = app(TicketStateMachine::class);

        foreach ([TicketStatus::Diagnosing, TicketStatus::Repairing, TicketStatus::Ready] as $status) {
            $machine->transition($ticket->fresh() ?? $ticket, $status, $this->owner->id);
        }

        app(DeliverTicket::class)->deliver(
            $ticket->fresh() ?? $ticket,
            [['description' => 'دستمزد', 'amount' => 3_000_000]],
            [['method' => 'cash', 'amount' => 3_000_000, 'account_id' => $cashId]],
            30,
            $this->owner->id,
        );

        expect($warehouse)->not->toBeNull();
    });

    $response = $this->get($this->url.'/t/'.$ticket->tracking_token);

    $response->assertOk();

    // Zero, not the 3,000,000 estimate it used to recite.
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('ticket.amount_due.value', 0)
        // And it must not still be labelled a guess: a real bill exists.
        ->where('ticket.is_estimate', false)
    );
});

it('shows what is genuinely still outstanding when the customer paid only part', function (): void {
    $ticket = trackedTicket(withParty: true);

    ($this->inTenant)(function () use ($ticket): void {
        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        $cashId = (int) $cash->id;
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        $machine = app(TicketStateMachine::class);

        foreach ([TicketStatus::Diagnosing, TicketStatus::Repairing, TicketStatus::Ready] as $status) {
            $machine->transition($ticket->fresh() ?? $ticket, $status, $this->owner->id);
        }

        // Billed 3,000,000, paid 1,000,000 in cash and the rest on account.
        app(DeliverTicket::class)->deliver(
            $ticket->fresh() ?? $ticket,
            [['description' => 'دستمزد', 'amount' => 3_000_000]],
            [['method' => 'cash', 'amount' => 1_000_000, 'account_id' => $cashId]],
            0,
            $this->owner->id,
        );
    });

    $this->get($this->url.'/t/'.$ticket->tracking_token)
        ->assertOk()
        // The real outstanding balance, from the invoice — not from an estimate that
        // never knew a payment had been made.
        ->assertInertia(fn (AssertableInertia $page) => $page->where('ticket.amount_due.value', 2_000_000));
});

it('still shows the estimate while there is no bill yet', function (): void {
    $ticket = trackedTicket();

    // Nothing has been billed, so the guess is the best the shop can honestly offer.
    $this->get($this->url.'/t/'.$ticket->tracking_token)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ticket.amount_due.value', 3_000_000)
            ->where('ticket.is_estimate', true)
        );
});

it('records which invoice settled the repair', function (): void {
    $ticket = trackedTicket(withParty: true);

    ($this->inTenant)(function () use ($ticket): void {
        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        $cashId = (int) $cash->id;
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        $machine = app(TicketStateMachine::class);

        foreach ([TicketStatus::Diagnosing, TicketStatus::Repairing, TicketStatus::Ready] as $status) {
            $machine->transition($ticket->fresh() ?? $ticket, $status, $this->owner->id);
        }

        $invoice = app(DeliverTicket::class)->deliver(
            $ticket->fresh() ?? $ticket,
            [['description' => 'دستمزد', 'amount' => 3_000_000]],
            [],
            0,
            $this->owner->id,
        );

        // "Has this repair been paid for?" used to be answerable only by parsing the
        // Persian sentence in the invoice's notes column.
        expect(($ticket->fresh() ?? $ticket)->sales_invoice_id)->toBe($invoice->getKey());
    });
});

/*
| The tracking page and the approval link have separate budgets.
|
| They did not. Laravel's guest rate-limit key is `sha1($domain.'|'.$ip)` — the URI is
| not in it — so two unnamed `throttle:N,1` groups share ONE counter and merely check it
| against different ceilings. A customer refreshing their status page ten times spent the
| whole approval allowance, and the link they had been texted answered 429.
|
| Found by an adversarial sweep. Not a security hole — a shared counter can only shrink
| an attacker's budget — but the routes file documented two independent budgets and had
| one, and the person it inconveniences is a customer trying to say yes.
*/

it('does not spend the approval allowance on tracking refreshes', function (): void {
    $ticket = trackedTicket();

    ($this->inTenant)(fn () => app(QuoteApproval::class)->request($ticket, 3_000_000, $this->owner->id));

    /** @var RepairTicket $quoted */
    $quoted = ($this->inTenant)(fn (): RepairTicket => $ticket->fresh() ?? $ticket);

    // Well inside tracking's documented 30/minute...
    foreach (range(1, 12) as $ignored) {
        $this->get($this->url.'/t/'.$ticket->tracking_token)->assertOk();
    }

    // ...and the approval link, on its own budget of 10, still answers.
    $this->get($this->url.'/a/'.$quoted->approval_token)->assertOk();
});
