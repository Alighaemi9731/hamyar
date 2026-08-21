<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Services;

use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\Inventory\Services\BranchContext;
use Carbon\CarbonImmutable;

/**
 * What is falling due, and what is already late.
 *
 * ## The screen a shop actually opens every morning
 *
 * Not "all cheques" — «کدوم چک‌ها این هفته سررسیده». A shop with two hundred cheques on
 * file cares about eleven of them today, and a list that makes them find those eleven is a
 * list nobody opens twice.
 *
 * ## Issued cheques matter more than received ones here
 *
 * A received cheque falling due is an opportunity: bank it. An **issued** cheque falling
 * due is an obligation, and missing one is the single most damaging thing in this module —
 * a bounced cheque of the shop's own is a public credit failure that follows the owner
 * around the bazaar. So the buckets are computed per direction and the issued side is what
 * the dashboard leads with.
 *
 * ## Overdue means "due date passed and still open"
 *
 * Not "status = bounced". A cheque nobody has banked three weeks after its due date is
 * exactly the one nobody is watching, and it has no distinguishing status of its own —
 * which is why the calendar has to derive it rather than read it.
 */
final class ChequeCalendar
{
    /**
     * Cheques falling due within `$days`, and everything already overdue.
     *
     * @return array{overdue: list<Cheque>, due: list<Cheque>, total_overdue: int, total_due: int}
     */
    public function upcoming(ChequeDirection $direction, int $days = 7, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $horizon = $asOf->addDays(max(1, $days));

        $query = Cheque::query()
            ->with(['party:id,name', 'account:id,name'])
            ->where('direction', $direction->value)
            // Open, not merely un-cleared: a returned or written-off cheque has been dealt
            // with and does not belong on a list of things to do.
            ->whereIn('status', $this->openStatuses())
            /*
            | The horizon belongs in SQL, not only in the loop below.
            |
            | Without it this selected every open cheque the shop has — a couple of thousand
            | on an ordinary file — hydrated all of them with their party and account, and
            | then threw away everything past `$horizon` in PHP to render six lines. The
            | answer is identical either way: the loop keeps a cheque only if it is overdue
            | (`due_date` before today, which is before the horizon) or due by the horizon,
            | so nothing beyond `due_date <= $horizon` ever survived it.
            |
            | `cheques_tenant_due_idx (tenant_id, due_date)` serves this, and serves the
            | `order by due_date` with it.
            */
            ->where('due_date', '<=', $horizon)
            ->orderBy('due_date');

        /*
        | Constrained to the branch being viewed — and to the branches this user may see at
        | all, which is the half that is a permission rather than a filter (see
        | BranchContext). `includeUnassigned`, because `cheques.branch_id` is nullable and a
        | cheque with no branch is the shop's paper rather than nobody's: dropping it from a
        | per-branch view would make the two branches' lists fail to add up to the book.
        */
        $open = app(BranchContext::class)
            ->apply($query, 'cheques.branch_id', includeUnassigned: true)
            ->get();

        $overdue = [];
        $due = [];
        $totalOverdue = 0;
        $totalDue = 0;

        foreach ($open as $cheque) {
            if ($cheque->due_date->lessThan($asOf->startOfDay())) {
                $overdue[] = $cheque;
                $totalOverdue += $cheque->outstanding();

                continue;
            }

            if ($cheque->due_date->lessThanOrEqualTo($horizon)) {
                $due[] = $cheque;
                $totalDue += $cheque->outstanding();
            }
        }

        return [
            'overdue' => $overdue,
            'due' => $due,
            'total_overdue' => $totalOverdue,
            'total_due' => $totalDue,
        ];
    }

    /**
     * Still something to do about.
     *
     * @return list<string>
     */
    private function openStatuses(): array
    {
        return [
            ChequeStatus::InHand->value,
            ChequeStatus::Deposited->value,
            ChequeStatus::Presented->value,
            ChequeStatus::Bounced->value,
            ChequeStatus::ReturnedByEndorsee->value,
        ];
    }
}
