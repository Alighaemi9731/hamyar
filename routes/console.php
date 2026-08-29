<?php

declare(strict_types=1);

use App\Modules\Platform\Jobs\SendRenewalReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
*/

// Morning Tehran time: a shop owner who reads "your subscription renews in 3 days" at
// 09:00 can act on it that day. The same message at 02:00 is buried by breakfast.
Schedule::job(new SendRenewalReminders)
    ->dailyAt('09:00')
    ->timezone('Asia/Tehran')
    // The job is not idempotent across runs — a retry on the same day would send a
    // second reminder — so an overrun must skip rather than stack.
    ->withoutOverlapping()
    ->onOneServer();

// Late morning, after the renewal reminders and well inside shop hours: «دستگاه شما
// آماده است، لطفاً مراجعه کنید» arriving at 02:00 reads as spam and gets muted, which
// costs the shop the very customer it was trying to reach.
//
// `withoutOverlapping` and `onOneServer` are belt and braces here rather than the
// guarantee. The sweep is idempotent by design — every step is recorded under a unique
// index before it is announced (see AbandonedSweep) — precisely because a scheduler is
// a thing that runs twice, and neither of these flags survives a manual run.
Schedule::command('repairs:sweep-abandoned')
    ->dailyAt('10:00')
    ->timezone('Asia/Tehran')
    ->withoutOverlapping()
    ->onOneServer();

// Just after Tehran midnight, so a shop that lapsed overnight is already on the fallback
// plan's limits when it opens rather than three hours into the day. The command is
// idempotent — it moves rows by state and date, so a second run finds nothing to move.
//
// This is the only writer of `past_due`, `grace_ends_at` and `canceled`. Before it existed
// those three columns were read by `Subscription::isUsable()` and set by nothing, which
// meant there was no grace period at all and MRR counted every shop that had ever paid.
Schedule::command('subscriptions:expire')
    ->dailyAt('00:20')
    ->timezone('Asia/Tehran')
    ->withoutOverlapping()
    ->onOneServer();

// Weekly, at the quietest hour of the quietest day. Deleting old counter rows is the
// least urgent thing the system does and the easiest to forget: scheduled the day the
// table shipped, with a `/health` line, because every unscheduled sweep in this repo's
// history was discovered by its absence.
Schedule::command('quota:prune')
    ->weeklyOn(5, '03:30')
    ->timezone('Asia/Tehran')
    ->withoutOverlapping()
    ->onOneServer();
