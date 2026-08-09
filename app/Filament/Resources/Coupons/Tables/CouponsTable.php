<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Tables;

use App\Modules\Platform\Models\Coupon;
use App\Support\Money;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')->label('کد')->badge()->searchable()->copyable(),

                TextColumn::make('value')
                    ->label('تخفیف')
                    ->formatStateUsing(fn (int $state, Coupon $record): string => $record->type === 'percent'
                        ? "{$state}٪"
                        : Money::formatWithUnit($state)),

                TextColumn::make('redemptions')
                    ->label('استفاده‌شده')
                    ->formatStateUsing(fn (int $state, Coupon $record): string => $record->max_redemptions === null
                        ? (string) $state
                        : "{$state} / {$record->max_redemptions}"),

                TextColumn::make('expires_at')->label('انقضا')->dateTime('Y/m/d')->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make()->label('ویرایش'),
                // Deletable only while unused: once a coupon has been redeemed it is part
                // of an invoice's explanation, and removing it makes a past discount
                // unaccountable.
                DeleteAction::make()
                    ->label('حذف')
                    ->visible(fn (Coupon $record): bool => $record->redemptions === 0),
            ])
            ->toolbarActions([]);
    }
}
