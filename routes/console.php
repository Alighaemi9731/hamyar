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
