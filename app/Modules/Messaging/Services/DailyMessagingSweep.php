<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\CRM\Models\Party;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Messaging\Enums\AutomationKey;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\Settings\ShopSettings;
use Carbon\CarbonImmutable;

/**
 * The automations nothing emits an event for.
 *
 * ## Why a sweep exists at all
 *
 * Nothing happens when a due date arrives. No code runs, no model changes, no event fires —
 * the instalment simply becomes overdue because the earth moved. So somebody has to look,
 * once a day, and that is this.
 *
 * ## Every message is keyed by period, and the period is Jalali
 *
 * `installment-due-soon:{row}:1405-06-15` — the pattern from `docs/specs/treasury.md`,
 * which exists precisely so a scheduler that runs twice does not text a customer twice.
 * The key includes the DATE rather than just the row, because an overdue instalment is
 * chased on more than one day and each chase is a legitimately different message.
 *
 * A Gregorian key would be wrong for the same reason it is wrong for rent: the shop, the
 * contract and the customer all think in Jalali dates, and «قسط مرداد» must not land in two
 * buckets depending on which side of a month boundary it was swept.
 *
 * ## Quiet hours apply here and not to events
 *
 * A repair marked ready at 9pm texts immediately — the customer is waiting for exactly that
 * message. A birthday greeting at 2am is not a kindness, and neither is a due-date
 * reminder. So the sweep respects quiet hours and event-driven automations do not.
 */
final class DailyMessagingSweep
{
    public function __construct(
        private readonly Automations $automations,
        private readonly ShopSettings $settings,
    ) {}

    /**
     * @return array{queued: int, considered: int}
     */
    public function run(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now();

        if ($this->settings->messaging()->isQuietAt((int) $today->format('G'))) {
            // Not an error and not a skip forever: the scheduler runs hourly and the next
            // run inside working hours picks the same period up, because the key is the
            // date and not the moment.
            return ['queued' => 0, 'considered' => 0];
        }

        $queued = 0;
        $considered = 0;

        foreach ($this->installmentReminders($today) as $fired) {
            $considered++;
            $queued += $fired ? 1 : 0;
        }

        foreach ($this->chequeReminders($today) as $fired) {
            $considered++;
            $queued += $fired ? 1 : 0;
        }

        foreach ($this->birthdays($today) as $fired) {
            $considered++;
            $queued += $fired ? 1 : 0;
        }

        return ['queued' => $queued, 'considered' => $considered];
    }

    /**
     * T-3, T-0 and overdue, in one pass over the open rows.
     *
     * @return iterable<bool>
     */
    private function installmentReminders(CarbonImmutable $today): iterable
    {
        $rows = InstallmentRow::query()
            ->with(['plan.party.contacts'])
            ->whereIn('status', [InstallmentRow::STATUS_PENDING, InstallmentRow::STATUS_OVERDUE])
            ->whereBetween('due_at', [$today->subDays(60)->startOfDay(), $today->addDays(4)->endOfDay()])
            ->get();

        foreach ($rows as $row) {
            $plan = $row->plan;

            if ($plan === null) {
                continue;
            }

            $daysUntil = (int) $today->startOfDay()->diffInDays($row->due_at->startOfDay(), false);

            $key = match (true) {
                $daysUntil === 3 => AutomationKey::InstallmentDueSoon,
                $daysUntil === 0 => AutomationKey::InstallmentDueToday,
                $daysUntil < 0 => AutomationKey::InstallmentOverdue,
                default => null,
            };

            if ($key === null) {
                continue;
            }

            yield $this->automations->fire(
                $key,
                $plan->party,
                [
                    'name' => $plan->party?->name,
                    'amount' => Money::format($row->amount),
                    'due_date_j' => Jalali::format($row->due_at),
                    'plan_number' => $plan->number,
                    'shop' => config()->string('app.name'),
                ],
                // The DATE is in the key: an overdue instalment is chased on more than one
                // day, and each chase is a different message rather than a duplicate.
                idempotencyKey: "{$key->value}:{$row->id}:".Jalali::format($today),
                reference: $row,
                branchId: $plan->branch_id,
            );
        }
    }

    /**
     * Two days before a cheque falls due — received and issued both.
     *
     * The issued side matters more: a shop that forgets its own cheque is about to be
     * presented is a shop about to bounce one.
     *
     * @return iterable<bool>
     */
    private function chequeReminders(CarbonImmutable $today): iterable
    {
        $due = $today->addDays(2)->startOfDay();

        $cheques = Cheque::query()
            ->with('party.contacts')
            ->whereIn('status', [ChequeStatus::InHand->value, ChequeStatus::Deposited->value, ChequeStatus::Presented->value])
            ->whereBetween('due_date', [$due, $due->endOfDay()])
            ->get();

        foreach ($cheques as $cheque) {
            // Only the received side texts the party: an issued cheque's payee is a
            // supplier the shop owes, and telling them it is about to clear is nobody's
            // idea of a service. The issued reminder belongs on the shop's own dashboard,
            // which the cheque calendar already provides.
            if ($cheque->direction !== ChequeDirection::Received) {
                continue;
            }

            yield $this->automations->fire(
                AutomationKey::ChequeDueSoon,
                $cheque->party,
                [
                    'name' => $cheque->party?->name,
                    'amount' => Money::format($cheque->amount),
                    'due_date_j' => Jalali::format($cheque->due_date),
                    'serial' => $cheque->serial,
                    'shop' => config()->string('app.name'),
                ],
                idempotencyKey: 'cheque-due:'.$cheque->id.':'.Jalali::format($today),
                reference: $cheque,
            );
        }
    }

    /**
     * «تولدت مبارک».
     *
     * Matched on month and day, ignoring the year — which is the only way this works, since
     * a birthday is stored as a full date and the customer has one every year.
     *
     * @return iterable<bool>
     */
    private function birthdays(CarbonImmutable $today): iterable
    {
        $parties = Party::query()
            ->with('contacts')
            ->whereNotNull('birthday')
            ->whereRaw('extract(month from birthday) = ? and extract(day from birthday) = ?', [
                (int) $today->format('n'),
                (int) $today->format('j'),
            ])
            ->get();

        foreach ($parties as $party) {
            yield $this->automations->fire(
                AutomationKey::Birthday,
                $party,
                [
                    'name' => $party->name,
                    'shop' => config()->string('app.name'),
                ],
                // The Jalali YEAR, not the date: one greeting per customer per year, and a
                // sweep that runs every hour all day sends exactly one.
                idempotencyKey: 'birthday:'.$party->id.':'.substr(Jalali::format($today), 0, 4),
            );
        }
    }
}
