<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\TicketPart;
use Illuminate\Support\Facades\DB;

/**
 * The bench, measured — «کدام تکنسین چقدر کار کرد، و چقدر طول کشید».
 *
 * ## Delivered, not "worked on"
 *
 * A ticket counts for the technician who was assigned to it when it was **delivered**,
 * and it counts in the period it was delivered in. Counting intake instead would credit
 * work in the month it arrived rather than the month it was finished, so a device brought
 * in on the 29th of Mordad and repaired in Shahrivar would make Mordad look busy and
 * Shahrivar look idle. Neither month would describe what anybody did.
 *
 * Tickets still open are reported separately, as a count, because "how many are sitting on
 * this person's bench right now" is a different question from "how much did they finish"
 * and mixing them produces a number that answers neither.
 *
 * ## Turnaround is intake to delivery, wall-clock
 *
 * Not "time in the repairing status", which would flatter every technician by excluding
 * the days a device sat waiting for a part or for the customer to answer the phone. The
 * customer experienced the whole wait, and the whole wait is what a promised date has to
 * be set against. Where the shop wants to know *why* a job was slow, the status history
 * has it ticket by ticket; an average that quietly excludes waiting would hide it.
 *
 * ## Parts cost is the shop's cost, not the customer's price
 *
 * `ticket_parts` carries both. This reports `unit_cost` — what the part took out of stock
 * — because the figure beside a technician's name should not move when somebody edits a
 * price list.
 */
final class RepairReports
{
    /**
     * One row per technician, most jobs finished first.
     *
     * @param  list<int>|null  $branchIds  the branches to cover; null is every branch
     * @return list<array{technician: string, delivered: int, open: int, avg_turnaround_hours: int, parts_cost: int}>
     */
    public function technicianPerformance(ReportPeriod $period, ?array $branchIds = null): array
    {
        $delivered = DB::table('repair_tickets')
            ->leftJoin('users', 'users.id', '=', 'repair_tickets.technician_id')
            ->where('repair_tickets.status', TicketStatus::Delivered->value)
            ->whereNull('repair_tickets.deleted_at')
            ->whereNotNull('repair_tickets.delivered_at')
            ->whereBetween('repair_tickets.delivered_at', [$period->from, $period->to])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('repair_tickets.branch_id', $branchIds))
            ->groupBy('repair_tickets.technician_id', 'users.name')
            ->selectRaw("
                repair_tickets.technician_id as technician_id,
                coalesce(users.name, '') as technician,
                count(*) as delivered,
                coalesce(
                    round(avg(extract(epoch from (repair_tickets.delivered_at - repair_tickets.created_at)) / 3600)),
                    0
                ) as avg_turnaround_hours
            ")
            ->get();

        $open = $this->openByTechnician($branchIds);
        $partsCost = $this->partsCostByTechnician($period, $branchIds);

        $rows = [];

        foreach ($delivered as $row) {
            $values = (array) $row;
            $id = $this->intOf($values['technician_id'] ?? 0);

            $rows[] = [
                // An unassigned ticket is a real ticket and a real delay; it gets its own
                // row rather than being dropped, because "nobody was on it" is the finding.
                'technician' => $this->stringOf($values['technician'] ?? '') ?: 'بدون تکنسین',
                'delivered' => $this->intOf($values['delivered'] ?? 0),
                'open' => $open[$id] ?? 0,
                'avg_turnaround_hours' => $this->intOf($values['avg_turnaround_hours'] ?? 0),
                'parts_cost' => $partsCost[$id] ?? 0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['delivered'] <=> $a['delivered']);

        return $rows;
    }

    /**
     * What is on each bench right now — deliberately not filtered by the report's range.
     *
     * "Open" has no date to be inside: a ticket from two months ago that is still open is
     * open today, and excluding it because it fell outside the range would report an empty
     * bench for the technician who most needs help.
     *
     * @param  list<int>|null  $branchIds  the branches to cover; null is every branch
     * @return array<int, int>
     */
    private function openByTechnician(?array $branchIds): array
    {
        // `isOpenWork()`, the same predicate the Kanban board and the workload screen use
        // — so «۴ روی میز» here and four cards there are the same four. `ready` is not
        // open work: the bench has finished and the ball is in the customer's court.
        $openStatuses = array_values(array_map(
            static fn (TicketStatus $status): string => $status->value,
            array_filter(TicketStatus::cases(), static fn (TicketStatus $status): bool => $status->isOpenWork()),
        ));

        $rows = DB::table('repair_tickets')
            ->whereNull('deleted_at')
            ->whereIn('status', $openStatuses)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->groupBy('technician_id')
            ->selectRaw('technician_id, count(*) as open')
            ->get();

        $byId = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $byId[$this->intOf($values['technician_id'] ?? 0)] = $this->intOf($values['open'] ?? 0);
        }

        return $byId;
    }

    /**
     * Consumed parts only. A reservation is not a cost — the part is still on the shelf,
     * and a technician who planned a repair that was then cancelled has spent nothing.
     *
     * @param  list<int>|null  $branchIds  the branches to cover; null is every branch
     * @return array<int, int>
     */
    private function partsCostByTechnician(ReportPeriod $period, ?array $branchIds): array
    {
        $rows = DB::table('ticket_parts')
            ->join('repair_tickets', 'repair_tickets.id', '=', 'ticket_parts.repair_ticket_id')
            ->where('ticket_parts.state', TicketPart::STATE_CONSUMED)
            ->where('repair_tickets.status', TicketStatus::Delivered->value)
            ->whereNull('repair_tickets.deleted_at')
            ->whereBetween('repair_tickets.delivered_at', [$period->from, $period->to])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('repair_tickets.branch_id', $branchIds))
            ->groupBy('repair_tickets.technician_id')
            ->selectRaw('
                repair_tickets.technician_id as technician_id,
                coalesce(sum(ticket_parts.unit_cost * ticket_parts.quantity), 0) as parts_cost
            ')
            ->get();

        $byId = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $byId[$this->intOf($values['technician_id'] ?? 0)] = $this->intOf($values['parts_cost'] ?? 0);
        }

        return $byId;
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
