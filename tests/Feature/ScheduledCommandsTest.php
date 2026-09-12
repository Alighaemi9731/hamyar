<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/**
 * Every command the scheduler names is one artisan can find.
 *
 * ## Why this is a test and not something you would notice
 *
 * `Schedule::command('repairs:sweep-abandoned')` is a string. Nothing checks it when the
 * schedule is defined — the scheduler shells out to `php artisan <name>` at the appointed
 * minute, the child process prints "There are no commands defined", and the only record is
 * a line in a log nobody opens.
 *
 * That is exactly how the repairs sweep lived from Phase 6 on: written, tested through
 * its service, scheduled daily at 10:00, and never once run, because Laravel discovers
 * commands in `app/Console/Commands` only and a module command must be registered by its
 * provider. Nobody's uncollected device was ever nudged.
 *
 * Asserted against the real schedule in `routes/console.php`, so the next module command
 * that is scheduled and not registered fails here rather than in production silence.
 */
it('names only commands that artisan has registered', function (): void {
    $registered = array_keys(Artisan::all());

    $scheduled = collect(app(Schedule::class)->events())
        // `Schedule::job()` entries carry no command string; they dispatch a class.
        ->map(fn (Event $event): ?string => $event->command)
        ->filter()
        ->map(function (string $command): string {
            // `'/usr/bin/php' 'artisan' repairs:sweep-abandoned --flag` → the name.
            preg_match("/artisan'?\s+(\S+)/", $command, $matches);

            return $matches[1] ?? $command;
        })
        ->values()
        ->all();

    expect($scheduled)->not->toBeEmpty();

    foreach ($scheduled as $name) {
        expect($registered)->toContain($name);
    }
});
