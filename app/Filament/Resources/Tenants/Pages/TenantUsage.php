<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Models\UsageCounter;
use App\Modules\Platform\Models\UsageEvent;
use App\Modules\Platform\Services\Quota\LimitResolver;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\PeriodClock;
use App\Support\Quota\Window;
use App\Support\Tenancy\TenantContext;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What one shop has spent this month, against what it is allowed.
 *
 * The screen support opens when a shopkeeper says «چرا نمی‌تونم ثبت کنم» — and the reason
 * it exists rather than being answered from the database by hand: the effective limit is
 * not a column anywhere. It is the plan's row, unless an override beats it, unless the
 * subscription has lapsed and the fallback plan applies. Three places, one answer, and
 * `LimitResolver` is the only thing that knows it.
 *
 * Read-only on purpose. Changing a limit is the override relation manager on the edit
 * page, where it needs a reason typed beside it.
 */
final class TenantUsage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = TenantResource::class;

    protected static ?string $title = 'مصرف';

    protected string $view = 'filament-panels::resources.pages.list-records';

    public Tenant $record;

    public function mount(int|string $record): void
    {
        $this->record = Tenant::query()->findOrFail($record);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->counters())
            ->paginated(false)
            ->heading("مصرف {$this->record->name} — ".$this->periodLabel())
            ->columns([
                TextColumn::make('metric')
                    ->label('سهمیه')
                    ->formatStateUsing(fn (string $state): string => $this->label($state)),

                TextColumn::make('used')->label('مصرف‌شده'),

                TextColumn::make('limit')
                    ->label('سقف')
                    ->state(fn (UsageCounter $row): string => $this->limitFor($row->metric)),

                TextColumn::make('blocked_at')
                    ->label('متوقف شد')
                    ->dateTime()
                    ->placeholder('—')
                    ->color('danger'),
            ])
            ->emptyStateHeading('این فروشگاه در این ماه هنوز چیزی ثبت نکرده است.');
    }

    /**
     * Every event this shop has ever generated, newest first — the other half of the
     * support answer: not only "what is the number", but "when did we stop them, and how
     * much were they trying to do".
     *
     * @return list<UsageEvent>
     */
    public function recentEvents(): array
    {
        /** @var int $tenantId */
        $tenantId = $this->record->getKey();

        /** @var list<UsageEvent> $events */
        $events = app(TenantContext::class)->runAsPlatform(static fn (): array => UsageEvent::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->all());

        return $events;
    }

    /**
     * @return Builder<UsageCounter>
     */
    private function counters(): Builder
    {
        /** @var int $tenantId */
        $tenantId = $this->record->getKey();

        $periodKey = app(PeriodClock::class)->periodKey(Window::Month);

        return UsageCounter::query()
            ->where('tenant_id', $tenantId)
            ->where('period_key', $periodKey)
            ->orderBy('metric');
    }

    private function periodLabel(): string
    {
        return app(PeriodClock::class)->label(Window::Month);
    }

    private function label(string $metric): string
    {
        $registry = app(MetricRegistry::class);

        return $registry->has($metric) ? $registry->get($metric)->labelFa : $metric;
    }

    /**
     * The EFFECTIVE limit, through the resolver — never the plan's row read directly,
     * which would show the wrong number for every shop with an override or a lapse.
     */
    private function limitFor(string $metric): string
    {
        /** @var int $tenantId */
        $tenantId = $this->record->getKey();

        $limit = app(LimitResolver::class)->forTenant($tenantId, $metric);

        return $limit === null ? 'نامحدود' : (string) $limit;
    }
}
