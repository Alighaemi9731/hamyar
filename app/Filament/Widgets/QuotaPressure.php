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

        return app(TenantContext::class)->runAsPlatform(static fn (): Builder => UsageEvent::query()
            ->selectRaw('min(id) as id, metric')
            ->selectRaw("count(distinct tenant_id) filter (where kind in ('blocked', 'bulk_blocked')) as blocked_shops")
            ->selectRaw("count(distinct tenant_id) filter (where kind = 'upgraded_after') as converted_shops")
            ->where('created_at', '>=', $since)
            ->groupBy('metric')
            ->orderByRaw("count(distinct tenant_id) filter (where kind in ('blocked', 'bulk_blocked')) desc"));
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
