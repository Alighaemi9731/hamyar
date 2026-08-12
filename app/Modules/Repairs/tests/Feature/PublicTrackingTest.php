<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Services\TicketIntake;
use App\Modules\Repairs\Services\TicketStateMachine;
use App\Modules\Repairs\Services\TrackingLink;
use App\Support\Tenancy\TenantContext;

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
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
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

function trackedTicket(): RepairTicket
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

it('will not open another shop ticket on this hostname', function (): void {
    $ticket = trackedTicket();

    $other = Tenant::factory()->withDomain()->create();

    // Tenant A's token, on tenant B's hostname. RLS confines the lookup to the shop the
    // hostname resolved to, so there is no row to find.
    $this->get(tenantUrl($other).'/t/'.$ticket->tracking_token)->assertNotFound();
});

it('gives every ticket a different token', function (): void {
    $first = trackedTicket();
    $second = trackedTicket();

    expect($first->tracking_token)->not->toBe($second->tracking_token)
        ->and(strlen($first->tracking_token))->toBe(48);
});
