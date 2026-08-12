<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Events\TicketApproved;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Services\QuoteApproval;
use App\Modules\Repairs\Services\TicketIntake;
use App\Modules\Repairs\Services\TicketStateMachine;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/**
 * Asking the customer, and what they are actually agreeing to.
 *
 * The test that matters most here is the re-pricing one: everything else is plumbing,
 * but a customer approving a figure they were never shown is the shop taking money on a
 * misunderstanding it created.
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

function quotedTicket(int $amount = 4_500_000): RepairTicket
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var User $owner */
    $owner = test()->owner;

    /** @var RepairTicket $ticket */
    $ticket = inTenantContext($tenant, function () use ($warehouse, $owner, $amount): RepairTicket {
        $ticket = app(TicketIntake::class)->take([
            'branch_id' => $warehouse->branch_id,
            'device_model' => 'آیفون ۱۳',
            'reported_issue' => 'صفحه شکسته',
        ], $owner->id);

        app(TicketStateMachine::class)->transition($ticket, TicketStatus::Diagnosing, $owner->id);

        return app(QuoteApproval::class)->request($ticket, $amount, $owner->id);
    });

    return $ticket;
}

/* ------------------------------------------------- the re-pricing hazard -- */

it('records the figure the customer was shown, not the one it was changed to', function (): void {
    $ticket = quotedTicket(4_500_000);
    $token = $ticket->approval_token;

    ($this->inTenant)(function () use ($ticket): void {
        // Somebody edits the estimate after the link went out. This is the hazard: if
        // approval copied the CURRENT estimate, the customer would be agreeing to a
        // number they never saw.
        $ticket->forceFill(['estimate_amount' => 9_000_000])->save();
    });

    $this->post($this->url.'/a/'.$token.'/approve')->assertRedirect();

    ($this->inTenant)(function () use ($ticket): void {
        $approved = $ticket->fresh();

        expect($approved?->approved_amount)->toBe(4_500_000)
            ->and($approved?->approved_via)->toBe(RepairTicket::APPROVED_VIA_LINK);
    });
});

it('invalidates the old link when the shop re-quotes', function (): void {
    $ticket = quotedTicket(4_500_000);
    $firstToken = $ticket->approval_token;

    $second = ($this->inTenant)(function () use ($ticket): RepairTicket {
        return app(QuoteApproval::class)->request(
            $ticket->fresh() ?? $ticket,
            9_000_000,
            $this->owner->id,
        );
    });

    // A changed price is a new question, not a silent substitution in the old one.
    expect($second->approval_token)->not->toBe($firstToken);

    $this->post($this->url.'/a/'.$firstToken.'/approve')->assertNotFound();

    $this->post($this->url.'/a/'.$second->approval_token.'/approve')->assertRedirect();

    ($this->inTenant)(fn () => expect($ticket->fresh()?->approved_amount)->toBe(9_000_000));
});

/* ------------------------------------------------------------ the token -- */

it('spends the token so a link cannot be replayed', function (): void {
    $ticket = quotedTicket();
    $token = $ticket->approval_token;

    $this->post($this->url.'/a/'.$token.'/approve')->assertRedirect();

    // Single-use. A photographed message cannot re-authorise work.
    $this->post($this->url.'/a/'.$token.'/approve')->assertNotFound();
    $this->get($this->url.'/a/'.$token)->assertNotFound();
});

it('is not the tracking token', function (): void {
    $ticket = quotedTicket();

    // Tracking is printed on a receipt that lives in a pocket; approving commits
    // somebody to spending money. Different secrets, different lifetimes.
    expect($ticket->approval_token)->not->toBe($ticket->tracking_token);

    // And the tracking token cannot approve anything.
    $this->post($this->url.'/a/'.$ticket->tracking_token.'/approve')->assertNotFound();
});

it('answers a wrong approval token exactly like a missing one', function (): void {
    quotedTicket();

    $this->get($this->url.'/a/'.str_repeat('z', 48))->assertNotFound();
    $this->get($this->url.'/a/short')->assertNotFound();
});

it('will not let another shop approve this quote', function (): void {
    $ticket = quotedTicket();

    $other = Tenant::factory()->withDomain()->create();

    $this->post(tenantUrl($other).'/a/'.$ticket->approval_token.'/approve')->assertNotFound();
});

/* ------------------------------------------------------- what it unblocks -- */

it('lets work start once the customer has approved', function (): void {
    ($this->inTenant)(function (): void {
        $this->tenant->forceFill(['settings' => ['repairs' => ['approval_cap' => 1_000_000]]])->save();
    });
    app(TenantContext::class)->forget();

    $ticket = quotedTicket(4_500_000);

    ($this->inTenant)(function () use ($ticket): void {
        // Above the cap and unapproved — refused.
        expect(fn () => app(TicketStateMachine::class)->transition($ticket, TicketStatus::Repairing))
            ->toThrow(RuntimeException::class);
    });

    $this->post($this->url.'/a/'.$ticket->approval_token.'/approve');

    ($this->inTenant)(function () use ($ticket): void {
        app(TicketStateMachine::class)->transition(
            $ticket->fresh() ?? $ticket,
            TicketStatus::Repairing,
            $this->owner->id,
        );

        expect($ticket->fresh()?->status)->toBe(TicketStatus::Repairing);
    });
});

/* ----------------------------------------------------------- by phone -- */

it('records a phone approval with the staff member who took the call', function (): void {
    $ticket = quotedTicket();

    ($this->inTenant)(function () use ($ticket): void {
        app(QuoteApproval::class)->approveByPhone($ticket, $this->owner->id, 'تماس ساعت ۱۱');

        $approved = $ticket->fresh();

        // «who agreed to this?» has to name a person, not a URL.
        expect($approved?->approved_via)->toBe(RepairTicket::APPROVED_VIA_PHONE)
            ->and($approved?->approved_by)->toBe($this->owner->id)
            ->and($approved?->approval_note)->toBe('تماس ساعت ۱۱')
            ->and($approved?->approved_amount)->toBe(4_500_000);
    });
});

it('keeps the first answer when a customer approves twice', function (): void {
    $ticket = quotedTicket();

    $this->post($this->url.'/a/'.$ticket->approval_token.'/approve');

    ($this->inTenant)(function () use ($ticket): void {
        $approved = $ticket->fresh() ?? $ticket;
        $first = $approved->approved_at;

        // Narrowed rather than merely asserted: the comparison below needs a real
        // instant, and a null here would mean the link approval never landed.
        expect($first)->toBeInstanceOf(CarbonImmutable::class);

        if (! $first instanceof CarbonImmutable) {
            return;
        }

        // A customer taps, then rings to confirm. Not an error; the first answer stands.
        app(QuoteApproval::class)->approveByPhone($approved, $this->owner->id);

        $after = $ticket->fresh();

        expect($after?->approved_at?->equalTo($first))->toBeTrue()
            ->and($after?->approved_via)->toBe(RepairTicket::APPROVED_VIA_LINK);
    });
});

/* ----------------------------------------------------------- declining -- */

it('records a decline without closing the ticket', function (): void {
    $ticket = quotedTicket();

    $this->post($this->url.'/a/'.$ticket->approval_token.'/decline', ['note' => 'فعلاً منصرف شدم'])
        ->assertRedirect();

    ($this->inTenant)(function () use ($ticket): void {
        $declined = $ticket->fresh();

        expect($declined?->declined_at)->not->toBeNull()
            ->and($declined?->approved_at)->toBeNull()
            // Deliberately still awaiting: what happens to the device next — returned,
            // re-quoted lower, collected as-is — is a conversation, and the software
            // should not make that call for the shop.
            ->and($declined?->status)->toBe(TicketStatus::AwaitingApproval)
            // The token is spent either way; a "no" is an answer.
            ->and($declined?->approval_token)->toBeNull();
    });
});

/* -------------------------------------------------------------- events -- */

it('announces the approval with how it arrived', function (): void {
    Event::fake([TicketApproved::class]);

    $ticket = quotedTicket();

    $this->post($this->url.'/a/'.$ticket->approval_token.'/approve');

    Event::assertDispatched(
        TicketApproved::class,
        fn (TicketApproved $event): bool => $event->via === RepairTicket::APPROVED_VIA_LINK
    );
});

it('refuses to ask for approval of nothing', function (): void {
    ($this->inTenant)(function (): void {
        $ticket = app(TicketIntake::class)->take([
            'branch_id' => $this->warehouse->branch_id,
            'device_model' => 'گلکسی A54',
            'reported_issue' => 'باتری',
        ], $this->owner->id);

        expect(fn () => app(QuoteApproval::class)->request($ticket, 0, $this->owner->id))
            ->toThrow(RuntimeException::class, 'مبلغ برآورد');
    });
});
