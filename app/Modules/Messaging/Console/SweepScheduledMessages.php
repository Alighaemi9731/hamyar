<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Console;

use App\Modules\Messaging\Services\DailyMessagingSweep;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\InteractsWithTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The hourly run of the automations nothing emits an event for.
 *
 * ## Why this exists
 *
 * {@see DailyMessagingSweep} was written, tested and wired to five switches on the
 * messaging settings screen — and nothing ever called it. A shopkeeper could turn on the
 * instalment reminder, see it saved, and never have a customer reminded of anything. The
 * switch did not fail; it simply had no machinery behind it, which is worse, because
 * nothing about the screen says so.
 *
 * ## Once per shop, inside that shop
 *
 * The sweep reads the shop's own settings and quiet hours through `ShopSettings`, which
 * reads the tenant in context, and every query it makes is confined by RLS. So it runs per
 * tenant, pinned with `runFor()` by {@see InteractsWithTenants} — the same loop
 * `tenancy:sync-permissions` uses, and for the same three reasons:
 *
 * - **Suspended and archived shops are skipped** on an unattended run. A shop that has
 *   merely stopped paying is still `active` here and is swept: it falls back to the free
 *   plan (golden rule 7), and the SMS credit that plan allows is decided per message.
 * - **One shop throwing does not stop the rest.** The exception is reported, the loop
 *   carries on, and the command exits non-zero so the scheduler's log shows it.
 * - **The context is cleared afterwards**, so nothing a chained command does runs as the
 *   last shop swept.
 *
 * ## Quota, and why no `consume()` appears here
 *
 * The sweep only queues. Each message is counted in `SendSms` with `QuotaGuard::record()`
 * — the named `messaging.sms` exception to "consume inside the counted row's transaction",
 * because the message row is committed first by an idempotent insert — and a refusal
 * becomes a suppressed message with a reason the shop can read. `record()` never throws,
 * so no `QuotaExceeded` is expected on this path. If a future automation ever reaches
 * `consume()` from here, the loop reports it to the exception handler and fails the run;
 * it is not eaten.
 *
 * ## Safe to run by hand
 *
 * Every message is keyed by the shop's Jalali date (or year, for birthdays) under a unique
 * index, so a second run the same day sends nothing new and charges nothing. Somebody WILL
 * run this to see what it does, and it must not text a customer twice because they were
 * curious.
 */
final class SweepScheduledMessages extends Command
{
    use InteractsWithTenants;

    protected $signature = 'messaging:sweep {--tenant=* : Tenant slug or id; repeatable. Omit for all usable tenants}';

    protected $description = 'Queue the swept SMS automations — instalment and cheque reminders, birthdays — for every shop.';

    public function handle(): int
    {
        $totals = ['queued' => 0, 'considered' => 0];

        $exitCode = $this->eachTenant(function (Tenant $tenant) use (&$totals): void {
            // Resolved per shop rather than injected once: nothing in the sweep should be
            // able to carry one shop's state into the next, now or after a refactor.
            $result = app(DailyMessagingSweep::class)->run();

            $totals['queued'] += $result['queued'];
            $totals['considered'] += $result['considered'];

            $this->line(sprintf(
                '  %-24s considered %3d · queued %3d',
                $tenant->slug,
                $result['considered'],
                $result['queued'],
            ));
        });

        // The same stamp `quota:prune` leaves: an unscheduled sweep in this repo has always
        // been discovered by its absence, and a timestamp is how absence becomes visible.
        cache()->forever('messaging.swept_at', CarbonImmutable::now()->toIso8601String());

        $this->info(sprintf(
            'Swept: %d considered, %d queued.',
            $totals['considered'],
            $totals['queued'],
        ));

        return $exitCode;
    }
}
