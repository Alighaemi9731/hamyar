<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Contracts\SmsDriver;
use App\Modules\Messaging\Drivers\FakeSmsDriver;
use App\Modules\Messaging\Drivers\KavenegarDriver;
use App\Modules\Messaging\Listeners\SendAbandonedStepSms;
use App\Modules\Messaging\Listeners\SendInvoiceIssuedSms;
use App\Modules\Messaging\Listeners\SendRepairStatusSms;
use App\Modules\Repairs\Events\TicketEscalated;
use App\Modules\Repairs\Events\TicketStatusChanged;
use App\Modules\Sales\Events\InvoiceFinalised;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Messaging module.
 *
 * Spec: docs/specs/messaging.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class MessagingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        /*
        | The driver is resolved once, as a singleton, and that is load-bearing for tests.
        |
        | `FakeSmsDriver` accumulates what it was asked to send. Resolved fresh per
        | injection, each collaborator would get its own recorder and a test would assert
        | against an empty one while the message went to a different instance — a fake that
        | reports "nothing sent" while everything works is the worst kind of wrong.
        */
        $this->app->singleton(SmsDriver::class, function ($app): SmsDriver {
            $driver = config()->string('services.sms.driver', 'fake');

            if ($driver === 'kavenegar') {
                return new KavenegarDriver(config()->string('services.kavenegar.key', ''));
            }

            return new FakeSmsDriver;
        });
    }

    public function boot(): void
    {
        parent::boot();

        /*
        | Wired to the events Phases 5–7 already dispatch.
        |
        | No synthetic event names: a `messaging.repair_ready` invented here would drift
        | from `TicketStatusChanged` the first time somebody renamed the real thing, and the
        | automation would go quiet with no test failing. Every listener below names an
        | emitter that existed before this module.
        */
        Event::listen(TicketStatusChanged::class, SendRepairStatusSms::class);
        Event::listen(TicketEscalated::class, SendAbandonedStepSms::class);
        Event::listen(InvoiceFinalised::class, SendInvoiceIssuedSms::class);
    }
}
