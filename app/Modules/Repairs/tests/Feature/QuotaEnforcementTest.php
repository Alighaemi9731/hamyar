<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Models\TicketPart;
use App\Modules\Repairs\Services\TicketIntake;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The meter, at the counter where a device is handed over.
 *
 * ## Why this file exists separately from the guard's own suite
 *
 * `Platform/tests/Feature/Quota/*` proves the guard is correct: it counts, it refuses at
 * the ceiling, it is atomic under concurrency. Every one of those tests meters a synthetic
 * `quota.widgets` metric, deliberately, so that the guard's tests break when the guard
 * breaks rather than when Repairs renames something.
 *
 * The cost of that isolation is that **none of them touch a route**. A guard that is
 * perfect and never called is indistinguishable, from the shop floor, from no guard at
 * all — so this file drives `POST /repairs/intake` itself and asks the other half of the
 * question: does the refusal reach the operator as something a React component can render,
 * and was nothing written on the way.
 *
 * ## What Repairs sells, and what it does not
 *
 * `repairs.tickets` counts **قبض پذیرش** — devices taken in. One credit per device, spent
 * once, at the only moment the shop chooses to take on the work. Everything the ticket
 * does afterwards is free: a status moved, a part fitted, a quote approved, and — the one
 * that needed an owner's ruling — the invoice that settles the bill on the way out.
 *
 * That last one is DECISION GATE 6, item 5: a repair delivery finalises through
 * {@see App\Modules\Sales\Services\FinaliseInvoice} with `metered: false`, so it spends no
 * `sales.invoices` credit. The reasoning is worth keeping next to the test, because the
 * exemption looks like a loophole until you stand at the counter: the work was already
 * paid for at intake, and a shop whose sales credit ran out this month must still be able
 * to hand a customer back their own repaired phone. Refusing there is a one-star review,
 * and the loophole it leaves open is worth at most a repair-shaped sale.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse, Account, ProductVariant, Party} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);
        Account::factory()->create(['type' => Account::TYPE_INVENTORY]);

        // A component, not a handset: the parts picker refuses anything else, and the
        // fitted-part test below goes through the real picker.
        $product = Product::factory()->create(['name' => 'گلس', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        app(StockLedger::class)->record($variant->id, $warehouse->id, 10, MovementType::Purchase, unitCost: 200_000);

        return [$owner, $warehouse, $cash, $variant, Party::factory()->create()];
    });

    [$this->owner, $this->warehouse, $this->cash, $this->variant, $this->party] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * One device taken in, through the real intake form.
 *
 * Deliberately not asserting anything about the response — every test here wants to say
 * something different about it, and a helper that asserted success could not be used by
 * the tests about refusal.
 *
 * @return TestResponse<Response>
 */
function takeDeviceIn(): TestResponse
{
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var Party $party */
    $party = test()->party;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/repairs/intake', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => $party->id,
        'unit' => 'rial',
        'device_brand' => 'اپل',
        'device_model' => 'آیفون ۱۳',
        'device_imei' => '',
        'device_colour' => 'مشکی',
        'reported_issue' => 'روشن نمی‌شود',
        'priority' => 2,
        'estimate_amount' => 3_000_000,
        'prepaid_amount' => 0,
    ]);
}

function ticketCount(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $count */
    $count = inTenantContext($tenant, fn (): int => RepairTicket::query()->count());

    return $count;
}

/**
 * The ticket the intake above just wrote.
 */
function lastTicket(): RepairTicket
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var RepairTicket $ticket */
    $ticket = inTenantContext($tenant, fn (): RepairTicket => RepairTicket::query()->latest('id')->firstOrFail());

    return $ticket;
}

/**
 * A device taken in and walked to «آماده تحویل», every step through its real route.
 *
 * `queued → diagnosing → ready`, never through `repairing`: the approval cap guards that
 * one transition and defaults to "everything needs the customer's yes", so a fixture that
 * went that way would be testing the cap rather than the meter.
 */
function readyForDelivery(): RepairTicket
{
    takeDeviceIn()->assertSessionHasNoErrors();

    $ticket = lastTicket();

    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    foreach ([TicketStatus::Diagnosing, TicketStatus::Ready] as $status) {
        test()->actingAs($owner)
            ->post($url.'/repairs/tickets/'.$ticket->id.'/transition', ['status' => $status->value])
            ->assertSessionHasNoErrors();
    }

    return $ticket;
}

it('spends one repair credit for one device taken in', function (): void {
    takeDeviceIn()->assertSessionHasNoErrors()->assertRedirect();

    expect(quotaUsed($this->tenant, 'repairs.tickets'))->toBe(1);
});

it('refuses the intake that would cross the ceiling, and writes no ticket', function (): void {
    capQuota($this->tenant, 'repairs.tickets', 1);

    takeDeviceIn()->assertSessionHasNoErrors();
    expect(ticketCount())->toBe(1);

    // The second one is the whole test. A shop at its cap must be *told*, not silently
    // given a form that does nothing — the customer is standing at the counter holding
    // the phone, and an operator who gets no answer presses the button again.
    $blocked = takeDeviceIn();

    $blocked->assertSessionHasErrors('quota');
    expect(ticketCount())->toBe(1)
        ->and(quotaUsed($this->tenant, 'repairs.tickets'))->toBe(1);
});

it('hands the operator something to render, not just an error string', function (): void {
    capQuota($this->tenant, 'repairs.tickets', 0);

    takeDeviceIn();

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // These are the keys `quota-block.tsx` reads. A refusal that reached the browser
    // without them would render an empty card, which is worse than a 500 because nobody
    // would report it.
    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('repairs.tickets')
        // Persian, not the exception's English. `QuotaExceeded` stopped extending
        // `RuntimeException` precisely because this controller — like a dozen others —
        // catches one and turns it into a field message, and the English text used to
        // surface under «مدل دستگاه» while the block payload never reached the page.
        // That catch arm is three lines from the intake call, so this file is the thing
        // standing between it and a regression.
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded');
});

it('spends nothing when the intake fails inside the transaction', function (): void {
    // A checklist row with no title. {@see TicketIntake::recordChecklist()} refuses it
    // from inside the transaction — after the credit is consumed, after the ticket row
    // and after the first answer are written — which is exactly the shape claim 4 is
    // about: everything unwinds together, credit included.
    //
    // Driven against the service rather than `POST /repairs/intake`, and that is not a
    // shortcut: `TicketIntakeRequest` marks `checklist.*.label` required, so the form
    // refuses this body before the service ever sees it. The two are defence in depth,
    // not duplicates — the guard exists for the other callers of this public service
    // (Messaging's fixtures already are one), and it is the only failure in this module
    // that happens on the far side of `consume()`.
    inTenantContext($this->tenant, function (): void {
        /** @var Warehouse $warehouse */
        $warehouse = $this->warehouse;
        /** @var User $owner */
        $owner = $this->owner;

        expect(fn (): RepairTicket => app(TicketIntake::class)->take([
            'branch_id' => $warehouse->branch_id,
            'device_model' => 'آیفون ۱۳',
            'reported_issue' => 'صفحه شکسته',
            'checklist' => [
                ['item_key' => 'screen', 'label' => 'صفحه نمایش', 'answer' => 'شکسته'],
                ['item_key' => 'body', 'label' => '', 'answer' => 'سالم'],
            ],
        ], $owner->id))->toThrow(RuntimeException::class, 'هر ردیف چک‌لیست');
    });

    expect(ticketCount())->toBe(0)
        // No row at all, rather than a row reading zero: the two are different claims and
        // only the first one says the transaction really rolled back.
        ->and(quotaRowExists($this->tenant, 'repairs.tickets'))->toBeFalse();
});

/* ------------------------------------------ INTAKE IS THE ONLY TURNSTILE -- */

it('charges for taking the device in, and for nothing that happens to it afterwards', function (): void {
    takeDeviceIn()->assertSessionHasNoErrors();

    $ticket = lastTicket();

    expect(quotaUsed($this->tenant, 'repairs.tickets'))->toBe(1);

    // A bench moves a card several times a day, and a technician plans and fits parts as
    // the job reveals what it needs. Metering any of that would make a shop's bill depend
    // on how carefully it records the work — which is a tax on the record-keeping this
    // product exists to encourage, and the first thing a shop would optimise away.
    $this->actingAs($this->owner)
        ->post($this->url.'/repairs/tickets/'.$ticket->id.'/transition', ['status' => TicketStatus::Diagnosing->value])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->owner)
        ->post($this->url.'/repairs/tickets/'.$ticket->id.'/parts', [
            'variant_id' => $this->variant->id,
            'quantity' => 1,
            'unit_price' => 900_000,
        ])
        ->assertSessionHasNoErrors();

    /** @var TicketPart $part */
    $part = inTenantContext($this->tenant, fn (): TicketPart => TicketPart::query()->latest('id')->firstOrFail());

    $this->actingAs($this->owner)
        ->post($this->url.'/repairs/tickets/'.$ticket->id.'/parts/'.$part->id.'/consume')
        ->assertSessionHasNoErrors();

    // Still one. The device was taken in once, so the shop paid once.
    expect(quotaUsed($this->tenant, 'repairs.tickets'))->toBe(1);
});

/* --------------------------------- THE DELIVERY INVOICE IS EXEMPT (GATE 6) -- */

it('bills the repair without spending a sales invoice credit', function (): void {
    $ticket = readyForDelivery();

    $this->actingAs($this->owner)
        ->post($this->url.'/repairs/tickets/'.$ticket->id.'/deliver', [
            'unit' => 'rial',
            'warranty_days' => 30,
            'labour' => [['description' => 'دستمزد تعویض صفحه', 'amount' => 2_400_000]],
            'payments' => [['method' => 'cash', 'amount' => 2_400_000, 'account_id' => $this->cash->id]],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    inTenantContext($this->tenant, function (): void {
        /** @var SalesInvoice $invoice */
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        // A real, numbered, finalised sale — not a stub that would make the assertion
        // below true by producing nothing to count.
        expect($invoice->status)->toBe(InvoiceStatus::Final)
            ->and($invoice->number)->toBe('INV-000001')
            ->and($invoice->total)->toBe(2_400_000);
    });

    expect(quotaRowExists($this->tenant, 'sales.invoices'))->toBeFalse()
        // And the repair cost exactly what it cost at the door: one قبض, one credit.
        ->and(quotaUsed($this->tenant, 'repairs.tickets'))->toBe(1);
});

it('hands back a repaired phone even when the month has no sales credit left at all', function (): void {
    $ticket = readyForDelivery();

    // Zero, not "one short": the strongest form of the claim. If `DeliverTicket` ever
    // stops passing `metered: false`, this is the line that goes red — the one above
    // would still pass on a plan generous enough to absorb the extra credit.
    capQuota($this->tenant, 'sales.invoices', 0);

    $this->actingAs($this->owner)
        ->post($this->url.'/repairs/tickets/'.$ticket->id.'/deliver', [
            'unit' => 'rial',
            'warranty_days' => 0,
            'labour' => [['description' => 'دستمزد', 'amount' => 1_000_000]],
            'payments' => [['method' => 'cash', 'amount' => 1_000_000, 'account_id' => $this->cash->id]],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    inTenantContext($this->tenant, function () use ($ticket): void {
        expect($ticket->fresh()?->status)->toBe(TicketStatus::Delivered)
            ->and(SalesInvoice::query()->count())->toBe(1);
    });

    expect(quotaRowExists($this->tenant, 'sales.invoices'))->toBeFalse();
});
