<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Services\TicketIntake;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/**
 * The device passcode, and every way it could get out.
 *
 * A leaked unlock code is a stolen phone. The protection is four layers in four
 * different places — encrypted at rest, hidden from serialisation, gated by permission,
 * audited on reveal — and this file exists because each of those is easy to undo by
 * accident. An accessor added for convenience, a resource that spreads the model, a
 * `Log::info($ticket)` while debugging: any one of them re-opens the hole silently.
 *
 * The literal code used throughout is `4517`. Every assertion below hunts for that
 * string in a place it must never appear.
 */
const SECRET = '4517';

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User, Warehouse} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        // A technician who may work the bench but has NOT been given the reveal
        // permission. The default Technician role deliberately has it; this user is
        // stripped back to prove the gate is the permission and not the role name.
        $bench = User::factory()->create();
        $bench->assignRole('Salesperson');
        $bench->givePermissionTo('repairs.view', 'repairs.update');

        return [$owner, $bench, Warehouse::factory()->create()];
    });

    [$this->owner, $this->bench, $this->warehouse] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A ticket taken in with a passcode, through the real intake service.
 */
function ticketWithSecret(): RepairTicket
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
        'device_model' => 'آیفون ۱۳',
        'reported_issue' => 'روشن نمی‌شود',
        'device_passcode' => SECRET,
        'estimate_amount' => 2_000_000,
    ], $owner->id));

    return $ticket;
}

/* ------------------------------------------------------- 1. at rest -- */

it('never writes the passcode to the database in plaintext', function (): void {
    $ticket = ticketWithSecret();

    ($this->inTenant)(function () use ($ticket): void {
        // Read the raw column, bypassing the cast entirely — this is what a database
        // dump, a read replica or a backup on somebody's laptop actually contains.
        $raw = DB::table('repair_tickets')->where('id', $ticket->id)->value('device_passcode');

        expect($raw)->toBeString();

        $ciphertext = is_string($raw) ? $raw : '';

        expect($ciphertext)->not->toContain(SECRET)
            // Laravel's encrypter emits base64 of a JSON envelope; the marker proves it
            // is genuinely encrypted rather than merely encoded differently.
            ->and(base64_decode($ciphertext, true))->toContain('iv');

        // And it still decrypts for the one path allowed to read it.
        expect($ticket->fresh()?->device_passcode)->toBe(SECRET);
    });
});

/* ------------------------------------------------ 2. serialisation -- */

it('strips the passcode from every array and JSON form of the model', function (): void {
    $ticket = ticketWithSecret();

    ($this->inTenant)(function () use ($ticket): void {
        // These four are the paths that reach an Inertia prop, an API resource, an
        // Excel export and a queued job's payload respectively.
        expect($ticket->toArray())->not->toHaveKey('device_passcode')
            ->and(json_encode($ticket))->not->toContain(SECRET)
            ->and(json_encode($ticket->fresh()))->not->toContain(SECRET)
            ->and(collect([$ticket])->toJson())->not->toContain(SECRET);
    });
});

it('keeps the passcode out of the ticket page props', function (): void {
    $ticket = ticketWithSecret();

    $response = $this->actingAs($this->owner)->get($this->url.'/repairs/tickets/'.$ticket->id);

    $response->assertSuccessful();

    // The whole rendered payload, not just the ticket object — a secret in ANY prop is
    // a secret in the page source and in every screenshot of it.
    expect(json_encode($response->viewData('page')))->not->toContain(SECRET);

    // The page still knows a code exists, so the UI can offer the reveal button
    // without ever having held the value.
    $response->assertInertia(fn ($page) => $page->where('ticket.has_passcode', true));
});

/* -------------------------------------------------------- 3. logs -- */

it('keeps the passcode out of the log even when the whole model is logged', function (): void {
    $ticket = ticketWithSecret();

    $written = [];

    Log::listen(function ($message) use (&$written): void {
        $written[] = $message->message.' '.json_encode($message->context);
    });

    ($this->inTenant)(function () use ($ticket): void {
        // The careless thing a developer does at 2am. `$hidden` is what makes it safe,
        // because Monolog serialises through the same path as JSON.
        Log::info('debugging a ticket', ['ticket' => $ticket->toArray()]);
        Log::warning('and again', ['ticket' => $ticket]);
    });

    expect(implode("\n", $written))->not->toContain(SECRET);
});

/* --------------------------------------------------- 4. the reveal -- */

it('refuses the passcode to somebody without the permission', function (): void {
    $ticket = ticketWithSecret();

    // This user may view and update the ticket — they work the bench. Reading the
    // customer's unlock code is a different question, answered by a different
    // permission (Gate 1).
    $this->actingAs($this->bench)
        ->getJson($this->url.'/repairs/tickets/'.$ticket->id.'/passcode')
        ->assertForbidden();

    ($this->inTenant)(fn () => expect(Activity::query()->where('log_name', 'repairs')->count())->toBe(0));
});

it('hands over the passcode to somebody who has it, and writes down that it did', function (): void {
    $ticket = ticketWithSecret();

    $response = $this->actingAs($this->owner)
        ->getJson($this->url.'/repairs/tickets/'.$ticket->id.'/passcode');

    $response->assertSuccessful();
    expect($response->json('passcode'))->toBe(SECRET);

    ($this->inTenant)(function () use ($ticket): void {
        $audit = Activity::query()->where('log_name', 'repairs')->latest('id')->firstOrFail();

        // The protection is not that nobody can read it — a technician legitimately
        // needs to. It is that nobody can read it invisibly.
        expect($audit->description)->toContain($ticket->code)
            ->and($audit->causer_id)->toBe($this->owner->id)
            ->and($audit->subject_id)->toBe($ticket->id)
            // And the record itself must not quote the thing it is recording.
            ->and(json_encode($audit->properties))->not->toContain(SECRET);
    });
});

it('does not log a reveal when there was no passcode to reveal', function (): void {
    /** @var RepairTicket $ticket */
    $ticket = ($this->inTenant)(fn (): RepairTicket => app(TicketIntake::class)->take([
        'branch_id' => $this->warehouse->branch_id,
        'device_model' => 'گلکسی A54',
        'reported_issue' => 'باتری',
    ], $this->owner->id));

    $response = $this->actingAs($this->owner)
        ->getJson($this->url.'/repairs/tickets/'.$ticket->id.'/passcode');

    $response->assertSuccessful();
    expect($response->json('passcode'))->toBeNull();

    // Logging a reveal that revealed nothing would bury the real ones.
    ($this->inTenant)(fn () => expect(Activity::query()->where('log_name', 'repairs')->count())->toBe(0));
});

/* ----------------------------------------------------- 5. isolation -- */

it('will not hand another shop the passcode', function (): void {
    $ticket = ticketWithSecret();

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $intruder = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // Tenant A's ticket id, on tenant B's hostname, by a user who genuinely holds
    // `repairs.reveal_passcode` in their own shop.
    $this->actingAs($intruder)
        ->getJson(tenantUrl($other).'/repairs/tickets/'.$ticket->id.'/passcode')
        ->assertNotFound();
});
