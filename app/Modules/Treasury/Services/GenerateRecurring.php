<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\Treasury\Models\RecurringTemplate;
use App\Modules\Treasury\Models\RentalContract;
use App\Support\Jalali;
use Carbon\CarbonImmutable;

/**
 * Booking the rent, the wages and the desk income for a period that has arrived.
 *
 * ## Running twice must be harmless, because it will happen
 *
 * Not "might" — a retry after a timeout, a second worker on a bad deploy, an owner
 * pressing the button because they are not sure it ran. Each of those, done naively,
 * books a second month of rent, and nobody notices until the P&L is wrong by a figure
 * that looks plausible.
 *
 * The guarantee lives in the database, not here: every generated transaction carries
 * `generated_key = template:{id}:{jalali-period}` under a unique index, and a second
 * attempt collides on the insert and is swallowed. A check-then-insert would be enough for
 * one worker and useless for two — both would read "not yet" and both would write.
 *
 * ## Periods, not a pointer
 *
 * The generator asks "which periods between this template's start and today have not been
 * booked", and books them. There is no `last_run_at` to drift when a job dies halfway, and
 * a template switched off for three months and back on catches up its missed periods
 * rather than silently resuming from wherever a pointer stopped.
 *
 * ## Jalali months, because that is what a contract says
 *
 * Rent is due «اول هر ماه» — the first of each Jalali month. Adding 30 days drifts against
 * the calendar the tenant signed, and by the twelfth month the software and the paper
 * disagree about which month has been paid for. {@see Jalali::addMonths()} owns this.
 *
 * A day-of-month past the end of a short month is clamped rather than skipped: a template
 * set to the 31st still fires in a 30-day month, on the 30th. Skipping would lose a
 * month's rent every time Esfand came round.
 */
final class GenerateRecurring
{
    public function __construct(private readonly RecordCashTransaction $transactions) {}

    /**
     * Book every period that has fallen due and has not been booked.
     *
     * @return array{generated: int, skipped: int}
     */
    public function run(?CarbonImmutable $asOf = null, ?int $actorId = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $generated = 0;
        $skipped = 0;

        $templates = RecurringTemplate::query()
            ->with(['category', 'account'])
            ->where('is_active', true)
            ->get();

        foreach ($templates as $template) {
            $category = $template->category;
            $account = $template->account;

            /*
            | A template whose category or account has been soft-deleted is skipped, not
            | fatal. The foreign keys are `restrictOnDelete`, so this only happens via a
            | soft delete — and a scheduled command that dies on one orphaned template
            | would silently stop booking the rent for every other one.
            */
            if ($category === null || $account === null) {
                $skipped++;

                continue;
            }

            foreach ($this->duePeriods($template->starts_on, $template->ends_on, $template->day_of_month, $asOf) as $period => $dueOn) {
                $booked = $this->transactions->record(
                    category: $category,
                    account: $account,
                    amount: $template->amount,
                    occurredAt: $dueOn,
                    partyId: $template->party_id,
                    description: $template->name,
                    generatedKey: "template:{$template->id}:{$period}",
                    recurringTemplateId: $template->id,
                    actorId: $actorId,
                    branchId: $template->branch_id,
                );

                $booked === null ? $skipped++ : $generated++;
            }
        }

        foreach (RentalContract::query()->with(['category', 'account'])->get() as $contract) {
            $category = $contract->category;
            $account = $contract->account;

            if ($category === null || $account === null) {
                $skipped++;

                continue;
            }

            $end = $contract->terminated_on ?? $contract->ends_on;

            foreach ($this->duePeriods($contract->starts_on, $end, $contract->due_day, $asOf) as $period => $dueOn) {
                if (! $contract->isLiveOn($dueOn)) {
                    continue;
                }

                $booked = $this->transactions->record(
                    category: $category,
                    account: $account,
                    amount: $contract->monthly_amount,
                    occurredAt: $dueOn,
                    partyId: $contract->party_id,
                    description: "اجاره {$contract->title}",
                    reference: $contract->number,
                    generatedKey: "rental:{$contract->id}:{$period}",
                    rentalContractId: $contract->id,
                    actorId: $actorId,
                    branchId: $contract->branch_id,
                );

                $booked === null ? $skipped++ : $generated++;
            }
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * Every Jalali period from `starts` up to `asOf`, keyed `1405-06` with its due date.
     *
     * @return array<string, CarbonImmutable>
     */
    private function duePeriods(CarbonImmutable $starts, ?CarbonImmutable $ends, int $dayOfMonth, CarbonImmutable $asOf): array
    {
        $periods = [];
        $cursor = $starts;
        $guard = 0;

        // Bounded at 600 months. A template with a mistyped start date fifty years ago
        // should produce a bad month's data, not an infinite loop in a scheduled command.
        while ($guard++ < 600) {
            $due = Jalali::dayInMonthOf($cursor, $dayOfMonth);

            if ($due->greaterThan($asOf)) {
                break;
            }

            if ($ends !== null && $due->greaterThan($ends)) {
                break;
            }

            // Only from the period the template actually starts in: a contract signed on
            // the 20th does not owe rent for the 1st of that same month.
            if (! $due->lessThan($starts)) {
                $periods[Jalali::monthKey($due)] = $due;
            }

            $cursor = Jalali::addMonths($cursor, 1);
        }

        return $periods;
    }
}
