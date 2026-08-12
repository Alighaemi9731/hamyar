<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\CRM\Models\LoyaltyEntry;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyFollowUp;
use App\Modules\CRM\Models\PartyNote;
use App\Support\Timeline\TimelineEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * CRM's own contribution to the 360° timeline: money, notes, promises and points.
 *
 * The money lines come straight off `ledger_entries`, which means the timeline and the
 * statement can never disagree — they are the same rows read two ways. `amount` keeps
 * the ledger's sign convention (positive = the party owes the shop more), so a page
 * showing both does not have to reconcile two conventions.
 */
final class PartyTimeline
{
    /** Enough to fill a screen; the whole history lives on the statement tab. */
    private const PER_SOURCE = 60;

    /**
     * @return list<TimelineEntry>
     */
    public function for(int $partyId, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        return [
            ...$this->opening($partyId, $from, $to),
            ...$this->money($partyId, $from, $to),
            ...$this->notes($partyId, $from, $to),
            ...$this->followUps($partyId, $from, $to),
            ...$this->loyalty($partyId, $from, $to),
        ];
    }

    /**
     * The figure the shop carried in from whatever they used before.
     *
     * It lives in a column rather than as a ledger row, so nothing else would put it on
     * the timeline — and a customer page reading "he owes you 12,850,000" above "nothing
     * has ever happened" contradicts itself. Dated to when the record was created,
     * which is when the shop asserted it.
     *
     * @return list<TimelineEntry>
     */
    private function opening(int $partyId, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $party = Party::query()->find($partyId);

        if (! $party instanceof Party || $party->opening_balance === 0) {
            return [];
        }

        $at = $party->created_at;

        if (! $at instanceof CarbonImmutable) {
            return [];
        }

        if (($from instanceof CarbonImmutable && $at->isBefore($from))
            || ($to instanceof CarbonImmutable && $at->isAfter($to))) {
            return [];
        }

        return [new TimelineEntry(
            occurredAt: $at,
            kind: 'opening',
            title: 'مانده اولیه',
            description: 'مبلغی که هنگام ثبت این طرف حساب از دفتر قبلی منتقل شد.',
            amount: $party->opening_balance,
        )];
    }

    /**
     * @return list<TimelineEntry>
     */
    private function money(int $partyId, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $entries = [];

        $query = LedgerEntry::query()->where('party_id', $partyId);

        foreach ($this->window($query, 'occurred_at', $from, $to)->get() as $entry) {
            $isCharge = $entry->debit > 0;

            $entries[] = new TimelineEntry(
                occurredAt: $entry->occurred_at,
                kind: $isCharge ? 'charge' : 'payment',
                title: $isCharge ? 'بدهکار شد' : 'پرداخت / بستانکار شد',
                description: $entry->description,
                amount: $entry->signedAmount(),
            );
        }

        return $entries;
    }

    /**
     * @return list<TimelineEntry>
     */
    private function notes(int $partyId, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $entries = [];

        $query = PartyNote::query()->with('author:id,name')->where('party_id', $partyId);

        foreach ($this->window($query, 'created_at', $from, $to)->get() as $note) {
            $entries[] = new TimelineEntry(
                occurredAt: $note->created_at,
                kind: 'note',
                title: 'یادداشت',
                description: $note->body,
                actor: $note->author?->name,
            );
        }

        return $entries;
    }

    /**
     * Follow-ups appear at the moment they were *completed*, or at their due date while
     * still open.
     *
     * A promise that has not been kept belongs on the timeline at the moment it matters
     * — its due date — not at the moment somebody typed it.
     *
     * @return list<TimelineEntry>
     */
    private function followUps(int $partyId, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $entries = [];

        $query = PartyFollowUp::query()->with('assignee:id,name')->where('party_id', $partyId);

        foreach ($this->window($query, 'due_at', $from, $to)->get() as $followUp) {
            $done = $followUp->isDone();

            $entries[] = new TimelineEntry(
                occurredAt: $followUp->done_at ?? $followUp->due_at,
                kind: 'follow_up',
                title: $done ? "پیگیری انجام شد: {$followUp->title}" : "پیگیری: {$followUp->title}",
                description: $followUp->body,
                actor: $followUp->assignee?->name,
            );
        }

        return $entries;
    }

    /**
     * @return list<TimelineEntry>
     */
    private function loyalty(int $partyId, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $entries = [];

        $query = LoyaltyEntry::query()->where('party_id', $partyId);

        foreach ($this->window($query, 'occurred_at', $from, $to)->get() as $entry) {
            $entries[] = new TimelineEntry(
                occurredAt: $entry->occurred_at,
                kind: 'loyalty',
                title: $entry->labelFa(),
                description: $entry->description,
            );
        }

        return $entries;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function window(Builder $query, string $column, ?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        return $query
            ->when($from instanceof CarbonImmutable, fn (Builder $q) => $q->where($column, '>=', $from))
            ->when($to instanceof CarbonImmutable, fn (Builder $q) => $q->where($column, '<=', $to))
            ->orderByDesc($column)
            ->limit(self::PER_SOURCE);
    }
}
