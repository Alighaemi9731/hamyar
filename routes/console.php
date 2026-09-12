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

// Hourly, and not once a day at a chosen hour, because the hour is not ours to choose.
// Quiet hours are per shop (`messaging.quiet_until_hour` / `quiet_from_hour`), and a single
// 10:00 run would silently overrule a shop that opens at 11 — or never reach one whose
// window starts after it. So the sweep runs every hour and asks each shop, on Tehran's
// clock, whether now is a decent time; out of hours it returns empty-handed and the next
// run inside the window picks the same day up. On the hour in Tehran, so a 09:00 opening
// is met at 09:00 and not at 09:30.
//
// Twenty-four runs a day are safe because the sweep is idempotent by design: each message
// is keyed by the shop's Jalali date (or year, for a birthday) under a unique index, so
// a later run the same day finds every key taken in `SendSms` and sends nothing, charges
// nothing. `withoutOverlapping` and `onOneServer` spare the work, as with the repairs
// sweep; they are not the guarantee.
//
// The overlap lock expires after an hour rather than the default day: a run killed
// mid-sweep (a deploy, an OOM) leaves its mutex behind, and a day-long lock would silence
// every shop's reminders until tomorrow for the sake of one lost run.
Schedule::command('messaging:sweep')
    ->hourly()
    ->timezone('Asia/Tehran')
    ->withoutOverlapping(60)
    ->onOneServer();

// Just after Tehran midnight, so the rent due «اول هر ماه» is on the books before the shop
// opens on the first, and the daily close and the P&L read that morning already carry it.
// Not at 00:00 exactly: that minute belongs to the hourly SMS sweep, and nothing is gained by
// starting both at once. Tehran's midnight, not UTC's — UTC midnight is 03:30 in Tehran, and
// the generator decides "has this period fallen due" on the shop's calendar anyway.
//
// Nothing ran this before. `GenerateRecurring` had tests and a seeder and no schedule and
// no route, so no shop's rent or rental income was ever booked outside a test.
//
// Daily is enough because the generator asks which periods have not been booked rather
// than advancing a pointer: a missed night is caught up by the next one. Running it twice
// is harmless — every booking is keyed `template:{id}:{period}` under a unique index, so a
// second run finds every key taken and books nothing. `withoutOverlapping` and
// `onOneServer` spare the work; they are not the guarantee.
//
// The overlap lock expires after an hour rather than the default day, for the reason the
// SMS sweep gives: a run killed mid-way leaves its mutex behind, and a day-long lock taken
// at 00:10 is still held when tomorrow's 00:10 comes round.
Schedule::command('treasury:generate-recurring')
    ->dailyAt('00:10')
    ->timezone('Asia/Tehran')
    ->withoutOverlapping(60)
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
