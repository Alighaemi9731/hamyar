<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Events\TicketStatusChanged;
use App\Modules\Repairs\Exceptions\IllegalTicketTransition;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Services\TicketStateMachine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;

/**
 * The spine of the module.
 *
 * Every screen, every SMS and the public tracking page read one field, so the rules
 * about how it changes have to be exhaustive rather than representative.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();

    /** @var array{User, Warehouse} $fixtures */
    $fixtures = inTenantContext($this->tenant, fn (): array => [
        User::factory()->create(),
        Warehouse::factory()->create(),
    ]);

    [$this->user, $this->warehouse] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Set a repair setting on the shop.
 *
 * Called from OUTSIDE a tenant closure on purpose: `tenants` is a central table, and
 * dropping the context to re-read the settings has to happen before the next scoped
 * write, not in the middle of one.
 */
function shopSetting(string $key, int $value): void
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    $tenant->forceFill(['settings' => ['repairs' => [$key => $value]]])->save();
    app(TenantContext::class)->forget();
}

function ticketAt(TicketStatus $status, int $estimate = 0): RepairTicket
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;

    /** @var RepairTicket $ticket */
    $ticket = inTenantContext($tenant, fn (): RepairTicket => RepairTicket::factory()->create([
        'branch_id' => $warehouse->branch_id,
        'status' => $status,
        'estimate_amount' => $estimate,
    ]));

    return $ticket;
}

/* ----------------------------------------------------- every legal move -- */

it('allows every transition the map declares', function (TicketStatus $from, TicketStatus $to): void {
    $ticket = ticketAt($from);

    // A generous cap, so this dataset tests the map rather than the approval rule.
    shopSetting('approval_cap', 999_999_999);

    ($this->inTenant)(function () use ($ticket, $to): void {
        app(TicketStateMachine::class)->transition($ticket, $to, $this->user->id);

        expect($ticket->refresh()->status)->toBe($to);
    });
})->with(function (): array {
    $cases = [];

    foreach (TicketStatus::cases() as $from) {
        foreach ($from->allowedTransitions() as $to) {
            $cases["{$from->value} → {$to->value}"] = [$from, $to];
        }
    }

    return $cases;
});

/* --------------------------------------------------- every illegal move -- */

it('refuses every transition the map does not declare', function (TicketStatus $from, TicketStatus $to): void {
    $ticket = ticketAt($from);

    ($this->inTenant)(function () use ($ticket, $to, $from): void {
        expect(fn () => app(TicketStateMachine::class)->transition($ticket, $to, $this->user->id))
            ->toThrow(IllegalTicketTransition::class);

        // And nothing moved. A refusal that half-applies is worse than one that throws.
        expect($ticket->refresh()->status)->toBe($from)
            ->and($ticket->histories()->count())->toBe(0);
    });
})->with(function (): array {
    $cases = [];

    foreach (TicketStatus::cases() as $from) {
        foreach (TicketStatus::cases() as $to) {
            if ($from === $to || $from->canTransitionTo($to)) {
                continue;
            }

            $cases["{$from->value} ✗ {$to->value}"] = [$from, $to];
        }
    }

    return $cases;
});

/* ------------------------------------------------- the refusals that matter -- */

it('will not reopen a device that has left the shop', function (): void {
    foreach ([TicketStatus::Delivered, TicketStatus::Rejected] as $closed) {
        $ticket = ticketAt($closed);

        ($this->inTenant)(function () use ($ticket): void {
            // A fault appearing after handover is a NEW ticket, linked by the device.
            // Reopening this one would let somebody rewrite what was agreed at handover.
            expect(fn () => app(TicketStateMachine::class)->transition($ticket, TicketStatus::Repairing))
                ->toThrow(IllegalTicketTransition::class);
        });
    }
});

it('only calls a device abandoned if it was finished and nobody came', function (): void {
    // رسوبی means "we finished it and nobody collected it". A device abandoned while
    // still being diagnosed is not abandoned — somebody stopped working on it, which is
    // a different problem that this status would hide.
    foreach ([TicketStatus::Queued, TicketStatus::Diagnosing, TicketStatus::Repairing] as $open) {
        $ticket = ticketAt($open);

        ($this->inTenant)(fn () => expect(
            fn () => app(TicketStateMachine::class)->transition($ticket, TicketStatus::Abandoned)
        )->toThrow(IllegalTicketTransition::class));
    }

    $ready = ticketAt(TicketStatus::Ready);

    ($this->inTenant)(function () use ($ready): void {
        app(TicketStateMachine::class)->transition($ready, TicketStatus::Abandoned);

        expect($ready->refresh()->status)->toBe(TicketStatus::Abandoned)
            ->and($ready->abandoned_at)->not->toBeNull();
    });
});

it('lets a customer finally turn up for an abandoned device', function (): void {
    $ticket = ticketAt(TicketStatus::Abandoned);

    ($this->inTenant)(function () use ($ticket): void {
        app(TicketStateMachine::class)->transition($ticket, TicketStatus::Delivered, $this->user->id);

        expect($ticket->refresh()->status)->toBe(TicketStatus::Delivered);
    });
});

it('lets a device come back off the shelf when testing finds the fault again', function (): void {
    $ticket = ticketAt(TicketStatus::Ready);

    shopSetting('approval_cap', 999_999_999);

    ($this->inTenant)(function () use ($ticket): void {
        // A bench is not a pipeline. Refusing this would force a second ticket for one
        // device and break the history the module exists to keep.
        app(TicketStateMachine::class)->transition($ticket, TicketStatus::Repairing, $this->user->id);

        expect($ticket->refresh()->status)->toBe(TicketStatus::Repairing);
    });
});

/* ------------------------------------------------------------- history -- */

it('writes a history line for every move, with the actor', function (): void {
    $ticket = ticketAt(TicketStatus::Queued);

    ($this->inTenant)(function () use ($ticket): void {
        $machine = app(TicketStateMachine::class);

        $machine->transition($ticket, TicketStatus::Diagnosing, $this->user->id, 'باز شد');
        $machine->transition($ticket, TicketStatus::Ready, $this->user->id);

        $trail = $ticket->histories()->orderBy('id')->get();

        expect($trail)->toHaveCount(2);

        $first = $trail->firstOrFail();
        $second = $trail->last();

        expect($first->from_status)->toBe(TicketStatus::Queued)
            ->and($first->to_status)->toBe(TicketStatus::Diagnosing)
            ->and($first->actor_id)->toBe($this->user->id)
            ->and($first->note)->toBe('باز شد')
            ->and($second?->to_status)->toBe(TicketStatus::Ready);
    });
});

it('writes no history when a card is dropped back in its own column', function (): void {
    $ticket = ticketAt(TicketStatus::Repairing);

    ($this->inTenant)(function () use ($ticket): void {
        app(TicketStateMachine::class)->transition($ticket, TicketStatus::Repairing, $this->user->id);

        // Two staff pressing the same button is not two events. A trail full of
        // meaningless repeats is one where the real moves stop standing out.
        expect($ticket->histories()->count())->toBe(0);
    });
});

/* ------------------------------------------------------- the approval cap -- */

it('refuses to start work above the cap until an approval is recorded', function (): void {
    shopSetting('approval_cap', 5_000_000);

    $ticket = ticketAt(TicketStatus::Diagnosing, estimate: 12_000_000);

    ($this->inTenant)(function () use ($ticket): void {
        expect(fn () => app(TicketStateMachine::class)->transition($ticket, TicketStatus::Repairing))
            ->toThrow(RuntimeException::class, 'تا ثبت تأیید مشتری');

        // Diagnosing is NOT gated: producing the quote is what has to happen before
        // anybody can approve anything, and gating it would deadlock the ticket.
        app(TicketStateMachine::class)->transition($ticket, TicketStatus::AwaitingApproval);
        expect($ticket->refresh()->status)->toBe(TicketStatus::AwaitingApproval);

        $ticket->forceFill(['approved_at' => now(), 'approved_amount' => 12_000_000])->save();

        app(TicketStateMachine::class)->transition($ticket, TicketStatus::Repairing);
        expect($ticket->refresh()->status)->toBe(TicketStatus::Repairing);
    });
});

it('lets work below the cap start without asking anybody', function (): void {
    shopSetting('approval_cap', 5_000_000);

    $ticket = ticketAt(TicketStatus::Diagnosing, estimate: 900_000);

    ($this->inTenant)(function () use ($ticket): void {
        app(TicketStateMachine::class)->transition($ticket, TicketStatus::Repairing);

        expect($ticket->refresh()->status)->toBe(TicketStatus::Repairing);
    });
});

it('treats a cap of zero as every job needs approval', function (): void {
    // The default, and deliberately not the same as "no cap": a shop that has never
    // opened the settings screen has not told us what is safe to spend on a customer's
    // phone, and guessing generously is guessing with their money.
    $ticket = ticketAt(TicketStatus::Diagnosing, estimate: 50_000);

    ($this->inTenant)(fn () => expect(
        fn () => app(TicketStateMachine::class)->transition($ticket, TicketStatus::Repairing)
    )->toThrow(RuntimeException::class));
});

it('exempts nothing under a zero cap, not even an unquoted ticket', function (): void {
    // The hole the obvious `$estimate <= $cap` leaves: with the default cap of zero, a
    // ticket quoted at zero satisfies `0 <= 0` and work begins with nobody's permission.
    // An estimate of zero is not a free job — it is a job nobody has quoted yet, which
    // needs a customer's say-so more than a quoted one, not less.
    $ticket = ticketAt(TicketStatus::Diagnosing, estimate: 0);

    ($this->inTenant)(fn () => expect(
        fn () => app(TicketStateMachine::class)->transition($ticket, TicketStatus::Repairing)
    )->toThrow(RuntimeException::class, 'تا ثبت تأیید مشتری'));
});

it('fails closed on a malformed cap', function (TicketStatus $unused, mixed $cap): void {
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    // Anything that is not a positive integer resolves to zero, and zero exempts
    // nothing. The safe direction to be wrong in when the money belongs to somebody else.
    $tenant->forceFill(['settings' => ['repairs' => ['approval_cap' => $cap]]])->save();
    app(TenantContext::class)->forget();

    $ticket = ticketAt(TicketStatus::Diagnosing, estimate: 1_000);

    ($this->inTenant)(fn () => expect(
        fn () => app(TicketStateMachine::class)->transition($ticket, TicketStatus::Repairing)
    )->toThrow(RuntimeException::class));
})->with([
    'a negative cap' => [TicketStatus::Queued, -5_000_000],
    'a string' => [TicketStatus::Queued, 'خیلی زیاد'],
    'null' => [TicketStatus::Queued, null],
    'a float' => [TicketStatus::Queued, 1.5],
    'an array' => [TicketStatus::Queued, ['cap' => 10]],
]);

/* -------------------------------------------------------------- events -- */

it('announces the move only after it has committed', function (): void {
    Event::fake([TicketStatusChanged::class]);

    $ticket = ticketAt(TicketStatus::Diagnosing);

    ($this->inTenant)(function () use ($ticket): void {
        app(TicketStateMachine::class)->transition($ticket, TicketStatus::Ready, $this->user->id);
    });

    // Phase 8 texts "your phone is ready" from this. Dispatching inside the transaction
    // would send that message and then roll the status back.
    Event::assertDispatched(
        TicketStatusChanged::class,
        fn (TicketStatusChanged $event): bool => $event->to === TicketStatus::Ready
            && $event->from === TicketStatus::Diagnosing
            && $event->ticket->is($ticket)
    );
});

it('does not announce a refused move', function (): void {
    Event::fake([TicketStatusChanged::class]);

    $ticket = ticketAt(TicketStatus::Delivered);

    ($this->inTenant)(fn () => expect(
        fn () => app(TicketStateMachine::class)->transition($ticket, TicketStatus::Repairing)
    )->toThrow(IllegalTicketTransition::class));

    Event::assertNotDispatched(TicketStatusChanged::class);
});
