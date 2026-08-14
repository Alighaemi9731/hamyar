<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\CRM\Models\Party;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\MessageOptOut;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a campaign reaches, resolved at send time.
 *
 * ## Filters, never a frozen list
 *
 * A campaign built at 9am and sent at 6pm reaches whoever matches at 6pm. That is partly
 * about freshness and mostly about opt-out: a customer who asks to be left alone at noon
 * must not receive a message from a list built before they asked, and a frozen audience
 * cannot know.
 *
 * ## Opt-out is excluded here AND refused at the door
 *
 * Both, deliberately, and they are not redundant. Excluding here means the count a shop
 * sees before sending is honest — «۳۸۴ نفر» should not include people who will be silently
 * skipped. Refusing at the door in {@see SendSms} means it holds even if a future caller
 * builds an audience some other way.
 *
 * The one that matters legally is the door. The one that matters to a shopkeeper looking at
 * a number before spending their credit is this one.
 */
final class CampaignAudience
{
    /**
     * @return Builder<Party>
     */
    public function query(Campaign $campaign): Builder
    {
        /** @var array<string, mixed> $filters */
        $filters = $campaign->filters ?? [];

        $days = $filters['purchased_within_days'] ?? null;
        $owes = $filters['owes_at_least'] ?? null;

        return Party::query()
            ->with('contacts')
            ->where('is_active', true)
            // Somebody to text. A party with only a landline is not an audience member,
            // and counting them inflates every figure the shop sees.
            ->whereHas('contacts', fn (Builder $q) => $q->where('type', 'mobile'))
            /*
            | Opted-out numbers are excluded from the COUNT, not merely from the send.
            |
            | A shop deciding whether to spend 1,200,000 rial on a campaign is reading this
            | number. Showing 400 and sending 384 makes the estimate a lie in the direction
            | that costs them money.
            */
            ->whereDoesntHave('contacts', fn (Builder $q) => $q
                ->where('type', 'mobile')
                ->whereIn('value', $this->optedOutSpellings()))
            ->when(
                is_string($filters['kind'] ?? null),
                fn (Builder $q) => $q->where('kind', $filters['kind']),
            )
            ->when(
                is_array($filters['tags'] ?? null) && $filters['tags'] !== [],
                fn (Builder $q) => $q->whereHas('tags', fn (Builder $t) => $t->whereIn('tags.id', $filters['tags'])),
            )
            /*
            | An explicit subquery rather than a new `salesInvoices` relation on Party.
            |
            | CRM does not have one, and adding a relation from CRM to Sales to serve a
            | messaging filter would point a dependency the wrong way (golden rule 6) for
            | the convenience of one query.
            */
            ->when(
                is_int($days),
                fn (Builder $q) => $q->whereExists(fn ($sub) => $sub
                    ->selectRaw('1')
                    ->from('sales_invoices')
                    ->whereColumn('sales_invoices.party_id', 'parties.id')
                    ->whereNull('sales_invoices.deleted_at')
                    ->where('sales_invoices.issued_at', '>=', CarbonImmutable::now()->subDays(is_int($days) ? $days : 0))),
            )
            ->when(
                is_int($owes),
                fn (Builder $q) => $q->whereExists(fn ($sub) => $sub
                    ->selectRaw('1')
                    ->from('ledger_entries')
                    ->whereColumn('ledger_entries.party_id', 'parties.id')
                    ->havingRaw('coalesce(sum(debit), 0) - coalesce(sum(credit), 0) >= ?', [is_int($owes) ? $owes : 0])),
            );
    }

    public function count(Campaign $campaign): int
    {
        return $this->query($campaign)->count();
    }

    /**
     * A handful of names, so a shop can sanity-check the filter before spending credit.
     *
     * @return list<array{id: int, name: string, mobile: string|null}>
     */
    public function sample(Campaign $campaign, int $limit = 5): array
    {
        /** @var list<array{id: int, name: string, mobile: string|null}> $sample */
        $sample = $this->query($campaign)
            ->limit($limit)
            ->get()
            ->map(fn (Party $party): array => [
                'id' => $party->id,
                'name' => $party->name,
                'mobile' => $party->primaryMobile(),
            ])
            ->values()
            ->all();

        return $sample;
    }

    /**
     * Every stored spelling of an opted-out number.
     *
     * The opt-out list is canonical `+98…`; contact rows hold whatever somebody typed. So
     * the exclusion has to compare across spellings, and it does that by expanding each
     * canonical number back into the forms a contact row plausibly holds rather than by
     * normalising every contact row in SQL — which Postgres cannot do without a function
     * this migration does not install.
     *
     * @return list<string>
     */
    private function optedOutSpellings(): array
    {
        $spellings = [];

        foreach (MessageOptOut::query()->pluck('phone') as $phone) {
            $canonical = is_string($phone) ? $phone : '';
            $local = str_starts_with($canonical, '+98') ? '0'.substr($canonical, 3) : $canonical;

            $spellings[] = $canonical;
            $spellings[] = $local;
            $spellings[] = ltrim($local, '0');
            $spellings[] = '98'.ltrim($local, '0');
        }

        return array_values(array_unique($spellings));
    }
}
