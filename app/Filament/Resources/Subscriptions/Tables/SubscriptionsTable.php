<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Modules\Platform\Models\Subscription;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only across every shop.
 *
 * There is no edit action here on purpose. A subscription's state is the consequence of
 * invoices and payments; editing it directly would let someone grant a paid plan with no
 * corresponding money, and the ledger would no longer explain the access. Changes go
 * through BillingService.
 */
final class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('tenant.name')->label('فروشگاه')->searchable(),
                TextColumn::make('plan.name_fa')->label('پلن')->badge(),

                TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Subscription::STATUS_ACTIVE => 'فعال',
                        Subscription::STATUS_TRIALING => 'آزمایشی',
                        Subscription::STATUS_PAST_DUE => 'معوق',
                        default => 'لغوشده',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Subscription::STATUS_ACTIVE => 'success',
                        Subscription::STATUS_TRIALING => 'info',
                        Subscription::STATUS_PAST_DUE => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('current_period_end')
                    ->label('پایان دوره')
                    ->dateTime('Y/m/d')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('credit_balance')
                    ->label('اعتبار')
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? '—' : Money::formatWithUnit($state)),
            ])
            ->filters([
                SelectFilter::make('status')->label('وضعیت')->options([
                    Subscription::STATUS_ACTIVE => 'فعال',
                    Subscription::STATUS_TRIALING => 'آزمایشی',
                    Subscription::STATUS_PAST_DUE => 'معوق',
                    Subscription::STATUS_CANCELED => 'لغوشده',
                ]),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
