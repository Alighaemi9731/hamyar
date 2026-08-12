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
