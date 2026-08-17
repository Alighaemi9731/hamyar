<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Providers;

use App\Modules\Moadian\Contracts\MoadianDriver;
use App\Modules\Moadian\Drivers\FakeMoadianDriver;
use App\Modules\Moadian\Listeners\QueueInvoiceCancellation;
use App\Modules\Moadian\Listeners\QueueInvoiceSubmission;
use App\Modules\Sales\Events\InvoiceFinalised;
use App\Modules\Sales\Events\InvoiceVoided;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Moadian module.
 *
 * Spec: docs/specs/moadian.md · Ruling:
 * [ADR 0011](../../../../docs/adr/0011-moadian-adapter-without-a-provider.md)
 *
 * ## Ships disabled, with one driver, and that driver is a fake
 *
 * DECISION GATE 4 (part 2): no real intermediary provider for launch. The customers this
 * launches to are mostly on presumptive taxation and will not file electronically, and
 * choosing a provider before one has been asked for buys an integration the first real
 * request is likely to contradict.
 *
 * What ships is the part that is expensive to retrofit: the contract, the pure payload
 * mapping, the queue, the error inbox and the idempotent resend. Those are decisions about
 * *this* system and they are the same whichever provider is eventually chosen. When the
 * first paying tenant asks, a driver is written against this interface and nothing above it
 * moves.
 */
final class MoadianServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        /*
        | A singleton, and that is load-bearing for tests exactly as it is for `SmsDriver`.
        |
        | `FakeMoadianDriver` accumulates what it was asked to send and holds the queued
        | rejections a test set up. Resolved fresh per injection, a test would arm a
        | rejection on one instance and the job would submit through another — a fake that
        | reports "nothing sent" while everything works.
        */
        $this->app->singleton(MoadianDriver::class, function (): MoadianDriver {
            // One arm today, deliberately. The `moadian.driver` key is where a real
            // provider gets selected when one is chosen — see ADR 0011's backlog item.
            return new FakeMoadianDriver;
        });
    }

    public function boot(): void
    {
        parent::boot();

        /*
        | Wired to the two events Sales already dispatches — no synthetic event names, for
        | the reason Messaging states: an invented `moadian.invoice_issued` would drift from
        | the real emitter the first time somebody renamed it, and the submission would go
        | quiet with no test failing.
        |
        | Both listeners are no-ops while the feature flag is off, which at launch is every
        | shop. They still run on every finalisation, so "does nothing, writes nothing,
        | surfaces nothing" is a tested property rather than an assumption.
        */
        Event::listen(InvoiceFinalised::class, QueueInvoiceSubmission::class);
        Event::listen(InvoiceVoided::class, QueueInvoiceCancellation::class);
    }
}
