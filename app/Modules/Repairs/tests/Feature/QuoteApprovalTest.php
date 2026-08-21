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
use App\Modules\Repairs\Services\TrackingLink;
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
    $this->url = appUrl();

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

/*
| Rewritten for ADR 0017, not weakened.
|
| It used to post shop A's token at shop B's hostname and demand a 404 — the host chose
| the shop and RLS hid A's row from B's address. With one address that URL is the only
| approval URL there is, so the old assertion now demands a 404 from the very link the
| shop texted its customer, and it would have kept passing while demanding it.
|
| The token is globally unique now and names one ticket in one shop. What has to be true
| is therefore stronger and more specific: answering one shop's question answers only
| that shop's question, and does not spend anybody else's link.
*/

it('approves only the shop whose token it is', function (): void {
    $mine = quotedTicket(4_500_000);

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    /** @var RepairTicket $theirs */
    $theirs = inTenantContext($other, function (): RepairTicket {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create();

        $ticket = app(TicketIntake::class)->take([
            'branch_id' => $warehouse->branch_id,
            'device_model' => 'گلکسی S23',
            'reported_issue' => 'باتری',
        ], $owner->id);

        app(TicketStateMachine::class)->transition($ticket, TicketStatus::Diagnosing, $owner->id);

        return app(QuoteApproval::class)->request($ticket, 2_000_000, $owner->id);
    });

    // The other shop's customer says yes. Same host, same shape of link, different token.
    $this->post($this->url.'/a/'.$theirs->approval_token.'/approve')->assertRedirect();

    inTenantContext($other, function () use ($theirs): void {
        expect($theirs->fresh()?->approved_amount)->toBe(2_000_000);
    });

    // Ours is untouched — nobody has authorised a rial of work here — and the link is
    // still live, so their «yes» did not spend our single-use token either.
    ($this->inTenant)(function () use ($mine): void {
        $fresh = $mine->fresh();

        expect($fresh?->approved_at)->toBeNull()
            ->and($fresh?->approved_amount)->toBeNull()
            ->and($fresh?->approval_token)->toBe($mine->approval_token);
    });

    $this->get($this->url.'/a/'.$mine->approval_token)->assertOk();
})->group('isolation');

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

/* --------------------------------------------- the shop's half of it -- */

/*
| Everything above drives the service. These drive the routes a shopkeeper's fingers
| reach, and they exist because the Phase 6 DoD walk found the service had none: the
| public page could answer a question, and nothing in the application could ask one.
|
| A shop could not quote a job, could not produce a link to send, and could not write
| down a yes given over the phone — which is how most Iranian shops settle this. Every
| test above passed throughout.
*/

it('lets the shop quote a job and hands back a link to send', function (): void {
    $ticket = ($this->inTenant)(fn (): RepairTicket => app(TicketIntake::class)->take([
        'branch_id' => $this->warehouse->branch_id,
        'device_model' => 'آیفون ۱۳',
        'reported_issue' => 'صفحه شکسته',
    ], $this->owner->id));

    $this->actingAs($this->owner)
        ->post($this->url."/repairs/tickets/{$ticket->id}/approval/request", [
            'quoted_amount' => 4_500_000,
        ])
        ->assertRedirect();

    ($this->inTenant)(function () use ($ticket): void {
        /** @var RepairTicket $fresh */
        $fresh = $ticket->fresh();

        expect($fresh->status)->toBe(TicketStatus::AwaitingApproval)
            ->and($fresh->approval_quoted_amount)->toBe(4_500_000)
            ->and($fresh->approval_token)->toBeString()
            ->and(strlen((string) $fresh->approval_token))->toBe(48);

        // And the page can actually show it — the URL is built server-side from
        // `config('app.domain')` (golden rule 1b; it read a `domains` row until ADR 0017
        // retired per-shop hosts), never from whatever host the staff member is on.
        expect(app(TrackingLink::class)->approvalFor($fresh))
            ->toContain('/a/'.$fresh->approval_token);
    });
});

it('shows no link once the token has been spent', function (): void {
    $ticket = quotedTicket();

    ($this->inTenant)(function () use ($ticket): void {
        expect(app(TrackingLink::class)->approvalFor($ticket))->not->toBeNull();

        app(QuoteApproval::class)->approveByLink($ticket->fresh() ?? $ticket);

        // A dead link in front of somebody about to text it to a customer is worse than
        // no link: they send it, the customer taps it, and gets a 404 from the shop.
        expect(app(TrackingLink::class)->approvalFor($ticket->fresh() ?? $ticket))->toBeNull();
    });
});

it('records a phone approval from the ticket page, with the staff member who took the call', function (): void {
    $ticket = quotedTicket(4_500_000);

    $this->actingAs($this->owner)
        ->post($this->url."/repairs/tickets/{$ticket->id}/approval/approve", [
            'note' => 'با آقای رضایی تماس گرفته شد',
        ])
        ->assertRedirect();

    ($this->inTenant)(function () use ($ticket): void {
        /** @var RepairTicket $fresh */
        $fresh = $ticket->fresh();

        expect($fresh->approved_via)->toBe(RepairTicket::APPROVED_VIA_PHONE)
            ->and($fresh->approved_by)->toBe($this->owner->id)
            // The frozen figure, not today's estimate.
            ->and($fresh->approved_amount)->toBe(4_500_000);
    });
});

it('records a decline from the ticket page without closing the ticket', function (): void {
    $ticket = quotedTicket();

    $this->actingAs($this->owner)
        ->post($this->url."/repairs/tickets/{$ticket->id}/approval/decline", ['note' => 'گران بود'])
        ->assertRedirect();

    ($this->inTenant)(function () use ($ticket): void {
        /** @var RepairTicket $fresh */
        $fresh = $ticket->fresh();

        // Declining a quote means the work is not authorised. Whether the device goes
        // back unrepaired, gets re-quoted lower, or is collected as-is is a conversation
        // the shop still has to have.
        expect($fresh->declined_at)->not->toBeNull()
            ->and($fresh->status)->toBe(TicketStatus::AwaitingApproval);
    });
});

it('refuses a quote of nothing through the route, on the page rather than a 500', function (): void {
    $ticket = quotedTicket();

    $this->actingAs($this->owner)
        ->post($this->url."/repairs/tickets/{$ticket->id}/approval/request", ['quoted_amount' => 0])
        ->assertRedirect()
        ->assertSessionHasErrors('quoted_amount');
});

it('will not let another shop quote our ticket', function (): void {
    $ticket = quotedTicket();

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    /** @var User $intruder */
    $intruder = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->actingAs($intruder)
        ->post(appUrl()."/repairs/tickets/{$ticket->id}/approval/request", [
            'quoted_amount' => 1_000_000,
        ])
        ->assertNotFound();

    $this->actingAs($intruder)
        ->post(appUrl()."/repairs/tickets/{$ticket->id}/approval/approve", [])
        ->assertNotFound();
});

/* ------------------------------ the link outliving the job it was for -- */

/*
| A token minted for an open job stayed live after the job closed.
|
| `request()` refuses to quote a closed ticket. `record()` — the path the public link
| runs through — checked only whether an answer had already been given, and nothing
| cleared `approval_token` when a ticket reached a terminal state. So the ordinary
| workflow left a live link behind: quote the job, the customer does not answer, the shop
| hands the phone back and marks the ticket rejected. The SMS is still in somebody's
| inbox, and it still works.
|
| Whoever holds it — the customer, whoever else read the message, whoever has the handset
| the SMS landed on — could write a binding "yes, do the work at this price" onto a
| device that left the shop days earlier.
|
| Found by an adversarial sweep of the public surfaces, and confirmed here.
*/

it('kills the approval link when the ticket is closed', function (): void {
    $ticket = quotedTicket(4_500_000);
    $token = $ticket->approval_token;

    expect($token)->toBeString();

    // The customer never answered, so the shop hands the device back unrepaired.
    ($this->inTenant)(fn () => app(TicketStateMachine::class)->transition(
        $ticket->fresh() ?? $ticket,
        TicketStatus::Rejected,
        $this->owner->id,
        'مشتری جواب نداد؛ دستگاه تحویل داده شد',
    ));

    // The link that is still sitting in somebody's inbox.
    $this->post($this->url.'/a/'.$token.'/approve')->assertNotFound();

    ($this->inTenant)(function () use ($ticket): void {
        /** @var RepairTicket $fresh */
        $fresh = $ticket->fresh();

        expect($fresh->approved_at)->toBeNull()
            ->and($fresh->approved_amount)->toBeNull()
            // And the token is gone from the row, so nothing can render it either.
            ->and($fresh->approval_token)->toBeNull();
    });
});

it('refuses to record an approval against a closed ticket even if a token survived', function (): void {
    $ticket = quotedTicket(4_500_000);

    ($this->inTenant)(function () use ($ticket): void {
        app(TicketStateMachine::class)->transition(
            $ticket->fresh() ?? $ticket,
            TicketStatus::Rejected,
            $this->owner->id,
        );

        /** @var RepairTicket $closed */
        $closed = $ticket->fresh();

        // Belt and braces: put a token back by hand, as a stale row or an interleaved
        // request could, and prove the service itself still refuses.
        $closed->forceFill(['approval_token' => str_repeat('a', 48)])->save();

        expect(fn () => app(QuoteApproval::class)->approveByLink($closed))
            ->toThrow(RuntimeException::class);
    });
});
