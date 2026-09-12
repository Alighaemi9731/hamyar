<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Console;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Treasury\Services\GenerateRecurring;
use App\Support\Tenancy\InteractsWithTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The nightly booking of rent, wages and leased-desk income.
 *
 * ## Why this exists
 *
 * {@see GenerateRecurring} was written, tested three ways for idempotency, and used by the
 * crazy-month seeder — and nothing else ever called it. No schedule entry, no route, no
 * button. A shop could set up «اجاره ماهانه» and never see a month of it booked: the P&L
 * showed a profit the rent had already eaten, and the till looked fuller than it was. The
 * generator did not fail; it simply had no machinery behind it, the same way the SMS
 * automations had none until `messaging:sweep`.
 *
 * ## Once per shop, inside that shop
 *
 * Templates and rental contracts are tenant rows under RLS, so the generator runs per
 * tenant, pinned with `runFor()` by {@see InteractsWithTenants} — the loop
 * `messaging:sweep` uses, for the same reasons: suspended and archived shops are skipped
 * on an unattended run, one shop throwing is reported and does not stop the rest (the
 * command exits non-zero), and the context is cleared afterwards.
 *
 * ## Safe to run by hand, and twice
 *
 * Every booking carries `generated_key = template:{id}:{jalali-period}` (or `rental:…`)
 * under the unique index `cash_transactions_generated_once (tenant_id, generated_key)`. A
 * second run the same day — or the same month — collides on the insert, which
 * `RecordCashTransaction` answers with null, and books nothing twice. There is no pointer
 * to advance, so a night the scheduler missed is caught up by the next run rather than
 * lost.
 *
 * ## Quota, and why no `consume()` appears here
 *
 * `RecordCashTransaction` meters `treasury.cash_transactions` only for a hand-entered row
 * (`generatedKey === null`). A booking made from a template is the shop's own standing
 * cost, possibly several months of it after an outage, and refusing it would falsify the
 * books for something the shop did not do (ADR 0018's metric table records the same
 * rule). What a plan caps here is how many templates and contracts are active — standing
 * capacities, measured from live rows. So nothing on this path can throw `QuotaExceeded`;
 * if a future change makes it, `InteractsWithTenants` reports it and fails the run.
 */
final class GenerateRecurringBookings extends Command
{
    use InteractsWithTenants;

    protected $signature = 'treasury:generate-recurring {--tenant=* : Tenant slug or id; repeatable. Omit for all usable tenants}';

    protected $description = 'Book every recurring expense, income and rental period that has fallen due, for every shop.';

    public function handle(): int
    {
        $totals = ['generated' => 0, 'skipped' => 0];

        $exitCode = $this->eachTenant(function (Tenant $tenant) use (&$totals): void {
            // Resolved per shop rather than injected once, as in `messaging:sweep`: nothing
            // one shop's run holds should be able to reach the next.
            $result = app(GenerateRecurring::class)->run();

            $totals['generated'] += $result['generated'];
            $totals['skipped'] += $result['skipped'];

            $this->line(sprintf(
                '  %-24s booked %3d · already booked or skipped %3d',
                $tenant->slug,
                $result['generated'],
                $result['skipped'],
            ));
        });

        // The stamp `messaging:sweep` and `quota:prune` leave: an unscheduled run in this
        // repo has always been discovered by its absence.
        cache()->forever('treasury.recurring_generated_at', CarbonImmutable::now()->toIso8601String());

        $this->info(sprintf(
            'Recurring: %d booked, %d already booked or skipped.',
            $totals['generated'],
            $totals['skipped'],
        ));

        return $exitCode;
    }
}
