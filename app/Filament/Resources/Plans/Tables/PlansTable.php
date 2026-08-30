<?php

declare(strict_types=1);

namespace App\Filament\Resources\Plans\Tables;

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('name_fa')->label('نام')->searchable(),
                TextColumn::make('code')->label('کد')->badge()->color('gray'),

                TextColumn::make('price')
                    ->label('قیمت ماهانه')
                    ->formatStateUsing(fn (int $state): string => Money::formatWithUnit($state))
                    ->sortable(),

                TextColumn::make('limits_count')->counts('limits')->label('سهمیه'),

                TextColumn::make('subscriptions')
                    ->label('مشترک فعال')
                    ->state(fn (Plan $record): int => Subscription::query()
                        ->where('plan_id', $record->getKey())
                        ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
                        ->count()),

                IconColumn::make('is_public')->label('عمومی')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('ویرایش')])
            // No delete action, and no bulk delete. Subscriptions reference plans and an
            // invoice's meaning depends on the plan that produced it; a deleted plan
            // turns paid history into orphans. Retiring a plan is `is_public = false`.
            ->toolbarActions([]);
    }
}
