<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Modules\Platform\Models\UsageEvent;
use App\Support\Digits;
use App\Support\Quota\MetricRegistry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which limit is actually stopping shops, and whether being stopped makes them pay.
 *
 * The one screen that answers the question the pricing rests on. A limit that blocks
 * fifteen shops a week and converts eight of them is earning its place; one that blocks
 * fifteen and converts none is costing us fifteen customers a week, and there is no way
 * to tell those two apart by looking at revenue.
 *
 * Reads across every shop, so it lives inside the panel's `runAsPlatform()` context. The
 * numbers are counts of DISTINCT shops rather than of events: one shop hammering a limit
 * all month is one shop's opinion, not thirty.
 */
final class QuotaPressure extends BaseWidget
{
    protected static ?string $heading = 'کدام سقف بیشترین فشار را می‌سازد (۳۰ روز)';

    protected static ?int $sort = 40;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->pressure())
            ->paginated(false)
            ->columns([
                TextColumn::make('metric')
                    ->label('سهمیه')
                    ->formatStateUsing(fn (string $state): string => $this->label($state)),

                TextColumn::make('blocked_shops')
                    ->label('فروشگاه‌های متوقف‌شده')
                    ->formatStateUsing(static fn (int|string $state): string => Digits::toPersian((string) $state)),

                TextColumn::make('converted_shops')
                    ->label('ارتقا داده‌اند')
                    ->formatStateUsing(static fn (int|string $state): string => Digits::toPersian((string) $state))
                    ->color('success'),

                TextColumn::make('conversion')
                    ->label('نرخ تبدیل')
                    ->state(fn (UsageEvent $record): string => $this->rate($record))
                    ->badge()
                    ->color(fn (UsageEvent $record): string => $this->tone($record)),
            ])
            /*
            | The order the reader wants: the limit stopping the most shops, first.
            |
            | This is NOT what fixes the widget — see `pressure()`. Stating a default sort
            | was the obvious guess and it does not work, because Filament appends the
            | record key *in addition to* the default rather than instead of it; the
            | rendered SQL was `… desc, "blocked_shops" desc, "usage_events"."id" desc`,
            | still invalid against a grouped query. The subquery is what makes any
            | ordering legal. This line only says which one to read by.
            */
            ->defaultSort('blocked_shops', 'desc')
            ->emptyStateHeading('هنوز هیچ فروشگاهی به سقفی نخورده است.');
    }

    /**
     * One row per metric: how many distinct shops it blocked, and how many of those then
     * upgraded within the week.
     *
     * @return Builder<UsageEvent>
     */
    private function pressure(): Builder
    {
        $since = CarbonImmutable::now()->subDays(30);

        /*
        | The aggregate is a SUBQUERY, and that is the fix rather than a flourish.
        |
        | Filament always appends the record key as a final tiebreaker — `order by
        | "usage_events"."id" desc` — on top of whatever sort the table declares, so that
        | pagination is stable. Against a `group by metric` that is invalid SQL, and
        | Postgres refuses it: "column usage_events.id must appear in the GROUP BY clause
        | or be used in an aggregate function". Setting `defaultSort` does not help, because
        | the key sort is added *as well as* the default, not instead of it.
        |
        | Selecting `min(id) as id` was already an attempt to give Filament a key. It could
        | not work: `"usage_events"."id"` in ORDER BY resolves to the table's column, not to
        | the select alias, and the table's column is not grouped.
        |
        | Reading from a derived table makes every column — `id` included — an ordinary
        | column of that table, so any ordering Filament invents is legal. The alias is
        | `usage_events` so the model's own key reference still resolves.
        |
        | The widget threw a 500 on every dashboard load. Being a widget, the failure
        | surfaced as a full-screen overlay across the whole panel rather than one broken
        | card, so the platform panel was unusable — and it is the screen the pricing
        | decisions rest on.
        */
        return app(TenantContext::class)->runAsPlatform(static function () use ($since): Builder {
            // quota-scope-allow: reading across every shop is the entire point of this
            // widget — "which limit blocks the most shops" is unanswerable one shop at a
            // time — and it runs inside `runAsPlatform()`, which is the sanctioned way to
            // do it (golden rule 1, ADR 0002 amendment). The counts are already DISTINCT
            // by `tenant_id`, so no shop can dominate the answer by hammering a limit.
            $aggregate = DB::table('usage_events')
                ->selectRaw('min(id) as id, metric')
                ->selectRaw("count(distinct tenant_id) filter (where kind in ('blocked', 'bulk_blocked')) as blocked_shops")
                ->selectRaw("count(distinct tenant_id) filter (where kind = 'upgraded_after') as converted_shops")
                ->where('created_at', '>=', $since)
                ->groupBy('metric');

            // Reads the aggregate above and adds no rows of its own; the model is here for
            // Filament's record type, not to reach the table.
            // quota-scope-allow: platform-wide by design, inside runAsPlatform().
            return UsageEvent::query()->fromSub($aggregate, 'usage_events');
        });
    }

    private function label(string $metric): string
    {
        $registry = app(MetricRegistry::class);

        return $registry->has($metric) ? $registry->get($metric)->labelFa : $metric;
    }

    private function rate(UsageEvent $record): string
    {
        /** @var int $blocked */
        $blocked = $record->getAttribute('blocked_shops') ?? 0;
        /** @var int $converted */
        $converted = $record->getAttribute('converted_shops') ?? 0;

        if ($blocked === 0) {
            return '—';
        }

        return Digits::toPersian((string) (int) round($converted / $blocked * 100)).'٪';
    }

    /**
     * Green where the limit is selling, amber where it is merely irritating.
     *
     * A third of blocked shops upgrading is a good limit. None of them is a limit to
     * argue about at the next pricing review, which is exactly what this widget is for.
     */
    private function tone(UsageEvent $record): string
    {
        /** @var int $blocked */
        $blocked = $record->getAttribute('blocked_shops') ?? 0;
        /** @var int $converted */
        $converted = $record->getAttribute('converted_shops') ?? 0;

        return match (true) {
            $blocked === 0 => 'gray',
            $converted / $blocked >= 0.3 => 'success',
            $converted > 0 => 'warning',
            default => 'danger',
        };
    }
}
