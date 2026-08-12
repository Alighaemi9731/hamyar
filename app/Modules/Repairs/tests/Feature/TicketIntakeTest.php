<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use App\Support\Files\AttachmentStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * قبض پذیرش, through the form a counter actually posts.
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

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function intakePayload(int $branchId, array $overrides = []): array
{
    return [
        'branch_id' => $branchId,
        'unit' => 'rial',
        'device_brand' => 'اپل',
        'device_model' => 'آیفون ۱۳',
        'device_imei' => '',
        'device_colour' => 'مشکی',
        'reported_issue' => 'روشن نمی‌شود',
        'priority' => 2,
        'estimate_amount' => 3_000_000,
        'prepaid_amount' => 0,
        'accessories' => ['قاب', 'سیم‌کارت'],
        'checklist' => [
            ['item_key' => 'screen', 'label' => 'صفحه نمایش', 'answer' => 'شکسته', 'note' => 'گوشه بالا'],
            ['item_key' => 'body', 'label' => 'بدنه و قاب', 'answer' => 'سالم'],
        ],
        ...$overrides,
    ];
}

/* ---------------------------------------------------------- happy path -- */

it('takes a device in and lands on the printable receipt', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/repairs/intake', intakePayload($this->warehouse->branch_id))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $ticket = RepairTicket::query()->latest('id')->firstOrFail();

        expect($ticket->code)->toBe('REP-000001')
            ->and($ticket->status)->toBe(TicketStatus::Queued)
            ->and($ticket->device_model)->toBe('آیفون ۱۳')
            ->and($ticket->accessories)->toBe(['قاب', 'سیم‌کارت'])
            // 48 characters of CSPRNG, and NOT the code.
            ->and(strlen($ticket->tracking_token))->toBe(48)
            ->and($ticket->tracking_token)->not->toBe($ticket->code);

        // The first line of the trail. Without it a repair's history starts mid-story.
        expect($ticket->histories()->count())->toBe(1)
            ->and($ticket->histories()->first()?->from_status)->toBeNull();

        // The checklist is the record that settles «از قبل شکسته بود».
        expect($ticket->checklistAnswers()->count())->toBe(2);

        $screen = $ticket->checklistAnswers()->where('item_key', 'screen')->firstOrFail();

        expect($screen->answer)->toBe('شکسته')
            ->and($screen->note)->toBe('گوشه بالا')
            // Copied, not joined — the template gets edited, and an answer that
            // re-labels itself would rewrite what the customer signed.
            ->and($screen->label)->toBe('صفحه نمایش');
    });
});

it('links the ticket to a handset this shop sold', function (): void {
    /** @var ProductUnit $unit */
    $unit = ($this->inTenant)(function (): ProductUnit {
        $product = Product::factory()->serialized()->create(['name' => 'آیفون ۱۳']);
        $variant = ProductVariant::factory()->for($product)->create();

        return ProductUnit::factory()->for($variant, 'variant')->create([
            'warehouse_id' => $this->warehouse->id,
            'status' => UnitStatus::Sold,
            'imei1' => '356938035643809',
        ]);
    });

    $this->actingAs($this->owner)->post(
        $this->url.'/repairs/intake',
        intakePayload($this->warehouse->branch_id, ['device_imei' => '356938035643809']),
    )->assertSessionHasNoErrors();

    ($this->inTenant)(function () use ($unit): void {
        $ticket = RepairTicket::query()->latest('id')->firstOrFail();

        // The other half of the IMEI passport: bought from whom → sold to whom →
        // repaired when (golden rule 4).
        expect($ticket->product_unit_id)->toBe($unit->id);
    });
});

it('accepts an IMEI typed on a Persian keypad', function (): void {
    $this->actingAs($this->owner)->post(
        $this->url.'/repairs/intake',
        intakePayload($this->warehouse->branch_id, ['device_imei' => '۳۵۶۹۳۸۰۳۵۶۴۳۸۰۹']),
    )->assertSessionHasNoErrors();

    ($this->inTenant)(fn () => expect(
        RepairTicket::query()->latest('id')->firstOrFail()->device_imei
    )->toBe('356938035643809'));
});

it('takes the device in even when the IMEI fails its check digit', function (): void {
    // Fifteen digits copied off a cracked screen by a clerk with a customer waiting is
    // wrong often enough to matter. The number is evidence, not a key — refusing the
    // intake over a typo would send a paying customer away.
    $this->actingAs($this->owner)->post(
        $this->url.'/repairs/intake',
        intakePayload($this->warehouse->branch_id, ['device_imei' => '356938035643801']),
    )->assertSessionHasNoErrors();

    ($this->inTenant)(fn () => expect(
        RepairTicket::query()->latest('id')->firstOrFail()->device_imei
    )->toBe('356938035643801'));
});

/* ------------------------------------------------------------- photos -- */

it('keeps intake photos against the ticket', function (): void {
    Storage::fake('local');

    $this->actingAs($this->owner)->post(
        $this->url.'/repairs/intake',
        intakePayload($this->warehouse->branch_id, [
            'photos' => [
                UploadedFile::fake()->image('screen.jpg'),
                UploadedFile::fake()->image('back.jpg'),
            ],
        ]),
    )->assertSessionHasNoErrors();

    ($this->inTenant)(function (): void {
        $ticket = RepairTicket::query()->latest('id')->firstOrFail();

        $photos = app(AttachmentStore::class)->for($ticket, 'intake_photos');

        expect($photos)->toHaveCount(2)
            ->and($photos[0]->isImage())->toBeTrue()
            // The stored key is opaque and tenant-prefixed — never built from the name
            // the customer's phone gave the file.
            ->and($photos[0]->path)->toStartWith('t/'.$this->tenant->id.'/intake_photos/')
            ->and($photos[0]->path)->not->toContain('screen.jpg')
            // …and the original name survives for display.
            ->and($photos[0]->originalName)->toBe('screen.jpg');
    });
});

it('refuses a file that is not an image', function (): void {
    Storage::fake('local');

    $this->actingAs($this->owner)->post(
        $this->url.'/repairs/intake',
        intakePayload($this->warehouse->branch_id, [
            'photos' => [UploadedFile::fake()->create('payload.php', 8, 'application/x-php')],
        ]),
    )->assertSessionHasErrors('photos.0');
});

/* -------------------------------------------------------- the receipt -- */

it('prints a receipt carrying the tracking QR and no passcode', function (): void {
    $this->actingAs($this->owner)->post(
        $this->url.'/repairs/intake',
        intakePayload($this->warehouse->branch_id, ['device_passcode' => '4517']),
    );

    $ticket = ($this->inTenant)(fn () => RepairTicket::query()->latest('id')->firstOrFail());

    $response = $this->actingAs($this->owner)->get($this->url.'/repairs/tickets/'.$ticket->id.'/receipt');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Repairs::Tickets/Receipt')
            ->where('ticket.code', $ticket->code)
            ->has('tracking.qr_svg')
            // The checklist is on the customer's copy: a record kept only on the shop's
            // side is an assertion, not evidence.
            ->has('ticket.checklist', 2)
        );

    /** @var array<string, mixed> $props */
    $props = $response->viewData('page')['props'];

    // A receipt gets left on counters and photographed, so the code itself must be
    // nowhere in the payload.
    expect(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain('4517');

    // And the receipt must not even hint that one was recorded. Asserted on the ticket
    // payload rather than the whole page: the signed-in owner's own permission list
    // legitimately contains the string `repairs.reveal_passcode`, and matching that
    // would be catching the guard rather than a leak.
    /** @var array<string, mixed> $ticketProps */
    $ticketProps = $props['ticket'];

    expect(json_encode($ticketProps, JSON_UNESCAPED_UNICODE))->not->toContain('passcode');
});

it('takes in a bare phone with no accessories ticked', function (): void {
    $payload = intakePayload($this->warehouse->branch_id);

    // What a multipart form actually posts when nobody ticks a box: the key is simply
    // absent, because an empty array cannot be expressed in a multipart body. `present`
    // rejected this — and the intake form had nowhere to show the error, so the button
    // silently did nothing with a customer standing at the counter.
    unset($payload['accessories'], $payload['checklist']);

    $this->actingAs($this->owner)
        ->post($this->url.'/repairs/intake', $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $ticket = RepairTicket::query()->latest('id')->firstOrFail();

        expect($ticket->accessories)->toBe([])
            ->and($ticket->checklistAnswers()->count())->toBe(0);
    });
});

/* ------------------------------------------------------------ refusals -- */

it('refuses an intake with no model or no reported issue', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/repairs/intake', intakePayload($this->warehouse->branch_id, [
            'device_model' => '',
            'reported_issue' => '',
        ]))
        ->assertSessionHasErrors(['device_model', 'reported_issue']);
});

it('will not let another shop take a device in against this branch', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $intruder = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        Warehouse::factory()->create();

        return $user;
    });

    // Tenant A's branch id, posted on tenant B's hostname. `exists:branches,id` is
    // itself tenant-scoped by RLS, so this fails validation rather than leaking.
    $this->actingAs($intruder)
        ->post(tenantUrl($other).'/repairs/intake', intakePayload($this->warehouse->branch_id))
        ->assertSessionHasErrors('branch_id');
});
