<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Events\TicketAbandoned;
use App\Modules\Repairs\Events\TicketEscalated;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Models\TicketEscalation;
use App\Modules\Repairs\Services\AbandonedSweep;
use App\Modules\Repairs\Services\TicketStateMachine;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * رسوبی, and the property the whole design turns on: running it twice is harmless.
 *
 * A scheduler is a thing that runs twice — a retry, a second worker, somebody trying the
 * command by hand. Every duplicate run without this guarantee is a customer's phone
 * buzzing again.
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
 * A device that has been ready for the given number of days.
 */
function readyFor(int $days): RepairTicket
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;

    /** @var RepairTicket $ticket */
    $ticket = inTenantContext($tenant, fn (): RepairTicket => RepairTicket::factory()->create([
        'branch_id' => $warehouse->branch_id,
        'status' => TicketStatus::Ready,
        'ready_at' => CarbonImmutable::now()->subDays($days),
    ]));

    return $ticket;
}

/**
 * @param  array<string, mixed>  $settings
 */
function sweepSettings(array $settings): void
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    $tenant->forceFill(['settings' => ['repairs' => $settings]])->save();
    app(TenantContext::class)->forget();
}

/* ------------------------------------------------------- IDEMPOTENCY -- */

it('sends nothing the second time it runs', function (): void {
    Event::fake([TicketEscalated::class]);

    sweepSettings(['escalation_days' => [7, 30], 'abandoned_after_days' => 60]);

    readyFor(10);

    ($this->inTenant)(function (): void {
        $sweep = app(AbandonedSweep::class);

        $first = $sweep->run();
        $second = $sweep->run();
        $third = $sweep->run();

        expect($first['escalated'])->toBe(1)
            // The property the whole design exists for. A retry, a second worker, or
            // somebody running the command to see what it does must not re-text anybody.
            ->and($second['escalated'])->toBe(0)
            ->and($third['escalated'])->toBe(0);
    });

    Event::assertDispatchedTimes(TicketEscalated::class, 1);
});

it('records the step before announcing it', function (): void {
    sweepSettings(['escalation_days' => [7], 'abandoned_after_days' => 60]);

    $ticket = readyFor(10);

    ($this->inTenant)(function () use ($ticket): void {
        app(AbandonedSweep::class)->run();

        // Announcing first and recording after would send the message and then lose the
        // record if the write failed — the same bug with worse timing.
        expect(TicketEscalation::query()->where('repair_ticket_id', $ticket->id)->count())->toBe(1);
    });
});

it('is protected by the database rather than by a check', function (): void {
    // A SELECT-then-INSERT would be enough for one worker and useless for two: both read
    // "not sent", both send. The guarantee has to live in the database, so the index is
    // asserted directly.
    //
    // Deliberately checked against the catalogue rather than by provoking a violation:
    // in Postgres a failed statement aborts the surrounding transaction, so a test that
    // triggers one poisons the connection it is running in and fails during teardown
    // instead of where the assertion is.
    $index = DB::selectOne(
        "select indexdef from pg_indexes
         where tablename = 'ticket_escalations' and indexname = 'ticket_escalations_once'"
    );

    expect($index)->not->toBeNull();

    $definition = is_object($index) && property_exists($index, 'indexdef') && is_string($index->indexdef)
        ? $index->indexdef
        : '';

    expect($definition)->toContain('UNIQUE')
        ->toContain('tenant_id')
        ->toContain('repair_ticket_id')
        ->toContain('step');
});

/* ------------------------------------------------------- the ladder -- */

it('fires each configured step once as the days pass', function (): void {
    Event::fake([TicketEscalated::class]);

    sweepSettings(['escalation_days' => [7, 30, 50], 'abandoned_after_days' => 60]);

    $ticket = readyFor(8);

    ($this->inTenant)(function () use ($ticket): void {
        $sweep = app(AbandonedSweep::class);

        $sweep->run();

        // Day 31: the second rung, and only the second.
        $ticket->forceFill(['ready_at' => CarbonImmutable::now()->subDays(31)])->save();
        expect($sweep->run()['escalated'])->toBe(1);

        // Same day again: nothing.
        expect($sweep->run()['escalated'])->toBe(0);

        $ticket->forceFill(['ready_at' => CarbonImmutable::now()->subDays(51)])->save();
        expect($sweep->run()['escalated'])->toBe(1);
    });

    Event::assertDispatchedTimes(TicketEscalated::class, 3);
});

it('lands on the step the shop is at rather than catching up one message a day', function (): void {
    Event::fake([TicketEscalated::class]);

    sweepSettings(['escalation_days' => [7, 30, 50], 'abandoned_after_days' => 60]);

    // The sweep was down; this device is already past every rung.
    readyFor(55);

    ($this->inTenant)(fn () => expect(app(AbandonedSweep::class)->run()['escalated'])->toBe(1));

    // One message, not three. The skipped rungs are recorded as due-and-not-sent so the
    // trail stays honest, but the customer's phone does not pay for an outage.
    Event::assertDispatchedTimes(TicketEscalated::class, 1);

    ($this->inTenant)(fn () => expect(TicketEscalation::query()->count())->toBe(3));
});

it('takes the ladder from the shop settings', function (): void {
    Event::fake([TicketEscalated::class]);

    // A bazaar shop that chases after three days.
    sweepSettings(['escalation_days' => [3], 'abandoned_after_days' => 60]);

    readyFor(4);

    ($this->inTenant)(fn () => expect(app(AbandonedSweep::class)->run()['escalated'])->toBe(1));

    Event::assertDispatchedTimes(TicketEscalated::class, 1);
});

it('ignores a malformed ladder and uses the default', function (mixed $configured): void {
    sweepSettings(['escalation_days' => $configured, 'abandoned_after_days' => 60]);

    // Default is [7, 30, 50]; a device ready eight days ago is on rung one.
    readyFor(8);

    ($this->inTenant)(fn () => expect(app(AbandonedSweep::class)->run()['escalated'])->toBe(1));
})->with([
    'a string' => ['هفت روز'],
    'an empty list' => [[]],
    'negatives' => [[-5, -1]],
    'null' => [null],
]);

/* ------------------------------------------------------------ رسوبی -- */

it('marks a long-uncollected device abandoned, once', function (): void {
    Event::fake([TicketAbandoned::class]);

    sweepSettings(['escalation_days' => [7], 'abandoned_after_days' => 60]);

    $ticket = readyFor(61);

    ($this->inTenant)(function () use ($ticket): void {
        $sweep = app(AbandonedSweep::class);

        expect($sweep->run()['abandoned'])->toBe(1)
            ->and($ticket->fresh()?->status)->toBe(TicketStatus::Abandoned)
            ->and($ticket->fresh()?->abandoned_at)->not->toBeNull();

        // No longer `ready`, so the next sweep does not see it at all.
        expect($sweep->run()['abandoned'])->toBe(0);
    });

    Event::assertDispatchedTimes(TicketAbandoned::class, 1);
});

it('does not chase a device that came back off the shelf for more work', function (): void {
    // A generous cap, so this test exercises the sweep rather than the (correctly
    // fail-closed) approval guard on `→ repairing`.
    sweepSettings([
        'escalation_days' => [7],
        'abandoned_after_days' => 60,
        'approval_cap' => 999_999_999,
    ]);

    $ticket = readyFor(40);

    ($this->inTenant)(function () use ($ticket): void {
        $machine = app(TicketStateMachine::class);

        // Testing found the fault again. `ready_at` is re-stamped, so the clock counts
        // from the last time the shop said it was finished — a device being reworked has
        // not been sitting uncollected.
        $machine->transition($ticket, TicketStatus::Repairing, $this->user->id);
        $machine->transition($ticket->fresh() ?? $ticket, TicketStatus::Ready, $this->user->id);

        expect(app(AbandonedSweep::class)->run()['escalated'])->toBe(0)
            ->and($ticket->fresh()?->status)->toBe(TicketStatus::Ready);
    });
});

it('leaves devices that are not ready alone', function (): void {
    sweepSettings(['escalation_days' => [1], 'abandoned_after_days' => 2]);

    ($this->inTenant)(function (): void {
        foreach ([TicketStatus::Queued, TicketStatus::Repairing, TicketStatus::Delivered] as $status) {
            RepairTicket::factory()->create([
                'branch_id' => $this->warehouse->branch_id,
                'status' => $status,
                'ready_at' => CarbonImmutable::now()->subDays(90),
            ]);
        }

        $result = app(AbandonedSweep::class)->run();

        // A device still on the bench is not uncollected, and a delivered one is gone.
        expect($result['considered'])->toBe(0)
            ->and($result['escalated'])->toBe(0)
            ->and($result['abandoned'])->toBe(0);
    });
});

/* --------------------------------------------------------- isolation -- */

it('sweeps each shop with its own ladder and its own tickets', function (): void {
    sweepSettings(['escalation_days' => [7], 'abandoned_after_days' => 60]);
    readyFor(10);

    $other = Tenant::factory()->withDomain()->create();

    inTenantContext($other, function (): void {
        Warehouse::factory()->create();
    });

    // The other shop has no tickets at all — RLS confines the sweep's query, so it
    // cannot see, nudge or abandon another shop's devices.
    inTenantContext($other, function (): void {
        expect(app(AbandonedSweep::class)->run())->toBe([
            'escalated' => 0,
            'abandoned' => 0,
            'considered' => 0,
        ]);
    });
});
