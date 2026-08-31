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
 * The literal code used throughout is the constant below. Every assertion here hunts for
 * that string in a place it must never appear.
 *
 * ## Why the sentinel is not a four-digit PIN
 *
 * It was `'4517'`, and these assertions grep an entire rendered payload for it. Every
 * Inertia page carries `auth.user.mobile` — eleven random digits from the factory — which
 * contains `4517` in **0.067% of runs** (measured over 200,000 samples), before counting
 * the tenant subdomain, the generated e-mail and every other random digit in the props.
 * So this file failed in CI roughly one run in a few hundred, with no leak, no code change
 * and nothing to find.
 *
 * A security test that cries wolf is worse than no security test, because the learned
 * response to a red run becomes "re-run it" — and that is exactly how a real leak
 * eventually gets waved through. It happened here on a PR touching three React files,
 * which cannot reach `viewData('page')` at all.
 *
 * A device password is not always a PIN; an alphanumeric screen lock is ordinary. So the
 * sentinel is one, and it keeps the original digits so its history is legible. No amount
 * of random digits can produce it by accident. Every assertion is otherwise unchanged and
 * still scans every prop.
 */
const SECRET = 'Qx7-4517-Lm2';

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

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
        ->getJson(appUrl().'/repairs/tickets/'.$ticket->id.'/passcode')
        ->assertNotFound();
});

/*
| The fifth way out, and the one every earlier test missed.
|
| Layers one to four all guard the passcode once it has reached the model. This one
| never gets that far: the intake form fails validation, Laravel redirects with the old
| input, and the framework's `dontFlash` default covers only the password fields. The
| session driver is `database` and `SESSION_ENCRYPT` is off, so the code lands in
| `sessions.payload` — in the clear, in the same database the encrypted column exists to
| protect, one table over.
|
| Found by an adversarial review of Phase 6, not by these tests, because every test above
| posted an intake that succeeded.
*/

/**
 * An intake that will be rejected, carrying the secret.
 *
 * @return array<string, mixed>
 */
function rejectedIntake(int $branchId): array
{
    return [
        'branch_id' => $branchId,
        'unit' => 'rial',
        'device_brand' => 'اپل',
        // Empty, so the FormRequest refuses the whole post. The trigger, not the point.
        'device_model' => '',
        'device_passcode' => SECRET,
        'reported_issue' => 'روشن نمی‌شود',
        'priority' => 2,
        'estimate_amount' => 3_000_000,
        'prepaid_amount' => 0,
    ];
}

it('does not flash the passcode into the session when the intake fails validation', function (): void {
    $response = $this->actingAs($this->owner)->post(
        $this->url.'/repairs/intake',
        rejectedIntake($this->warehouse->branch_id)
    );

    $response->assertSessionHasErrors('device_model');

    // The form comes back populated, which is the whole reason old input exists...
    $this->assertSame('اپل', session()->getOldInput('device_brand'));

    // ...but not with this field.
    expect(session()->getOldInput('device_passcode'))->toBeNull();
    expect(json_encode(session()->all(), JSON_UNESCAPED_UNICODE))->not->toContain(SECRET);
});

it('keeps the passcode out of the bytes a session driver would persist', function (): void {
    $this->actingAs($this->owner)->post(
        $this->url.'/repairs/intake',
        rejectedIntake($this->warehouse->branch_id)
    );

    /*
    | The companion to the test above, one layer down.
    |
    | That one asserts the value never entered the session array. This one asserts on
    | the bytes — because in production `SESSION_DRIVER=database` and `SESSION_ENCRYPT`
    | is false, so whatever is in that array is written verbatim into `sessions.payload`
    | and is what a database dump, a read replica or a nightly backup would show.
    |
    | `serialize()` is exactly what `Illuminate\Session\Store::prepareForStorage` hands
    | the driver, so this is the real payload rather than an approximation. The suite runs
    | on the `array` driver, and swapping `session.driver` mid-test does not help: the
    | manager resolved its driver when the request began, so the row that appears in
    | `sessions` belongs to no request in this test. Asserting on the serialised array
    | is the honest version of the same claim.
    */
    $payload = serialize(session()->all());

    expect($payload)->not->toContain(SECRET);

    // ...and the payload really does carry the old input, or the assertion above would
    // be passing on an empty session and proving nothing.
    expect($payload)->toContain('_old_input')->toContain('device_model');
});
