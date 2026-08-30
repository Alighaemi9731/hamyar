<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Modules\Platform\Services\Quota\LimitResolver;
use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-shop limits, negotiated.
 *
 * Every B2B product needs this, and the ones that skip it end up expressing it as a
 * secret plan nobody can find six months later. Here it is one row with a reason on it:
 * support raises a cap during a migration, a large customer buys the top rung and needs
 * fifty seats rather than twenty-five, a goodwill gesture after an outage.
 *
 * `reason` is required and `expires_at` exists for a purpose: an override with no reason
 * is indistinguishable from a mistake, and one with no end date is permanent, because
 * nobody comes back to tidy up a limit that is not hurting anybody.
 *
 * Saving bumps the shop's entitlement version so the change lands on its next request
 * rather than whenever a worker happens to restart.
 */
final class LimitOverridesRelationManager extends RelationManager
{
    protected static string $relationship = 'limitOverrides';

    protected static ?string $title = 'سقف‌های اختصاصی';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('metric')
                ->label('سهمیه')
                ->options(self::metricOptions())
                ->searchable()
                ->required()
                ->distinct(),

            TextInput::make('value')
                ->label('مقدار')
                ->numeric()
                ->minValue(0)
                ->helperText('خالی = نامحدود برای این فروشگاه.'),

            TextInput::make('reason')
                ->label('دلیل')
                ->required()
                ->maxLength(200)
                ->helperText('چرا این فروشگاه استثناست. شش ماه بعد، این تنها چیزی است که می‌ماند.'),

            DateTimePicker::make('expires_at')
                ->label('تا تاریخ')
                ->helperText('خالی = تا وقتی حذف نشود.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('metric')
            ->columns([
                TextColumn::make('metric')->label('سهمیه')->formatStateUsing(
                    static fn (string $state): string => self::metricOptions()[$state] ?? $state
                ),
                TextColumn::make('value')->label('مقدار')->formatStateUsing(
                    static fn (?int $state): string => $state === null ? 'نامحدود' : (string) $state
                ),
                TextColumn::make('reason')->label('دلیل')->wrap()->limit(60),
                TextColumn::make('expires_at')->label('تا تاریخ')->dateTime()->placeholder('بدون انقضا'),
            ])
            ->headerActions([CreateAction::make()->label('افزودن سقف اختصاصی')->after($this->bump(...))])
            ->recordActions([
                EditAction::make()->label('ویرایش')->after($this->bump(...)),
                DeleteAction::make()->label('حذف')->after($this->bump(...)),
            ]);
    }

    /**
     * An override changes what a shop may do, so the shop has to be told.
     */
    private function bump(): void
    {
        /** @var Model $tenant */
        $tenant = $this->getOwnerRecord();

        /** @var int $tenantId */
        $tenantId = $tenant->getKey();

        app(LimitResolver::class)->bump($tenantId);
    }

    /**
     * @return array<string, string>
     */
    private static function metricOptions(): array
    {
        $options = [];

        foreach (app(MetricRegistry::class)->all() as $metric) {
            /** @var Metric $metric */
            $options[$metric->key] = "{$metric->labelFa} ({$metric->key})";
        }

        return $options;
    }
}
