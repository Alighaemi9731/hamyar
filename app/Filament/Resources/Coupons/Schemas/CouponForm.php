<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Schemas;

use App\Support\Money;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')
                ->label('کد تخفیف')
                ->required()
                ->alphaDash()
                ->maxLength(30)
                ->unique(ignoreRecord: true)
                // Uppercased so CODE and code are the same coupon. Without this a
                // customer types it in lowercase, it fails, and support gets the call.
                ->dehydrateStateUsing(fn (string $state): string => mb_strtoupper($state)),

            Select::make('type')
                ->label('نوع')
                ->options(['percent' => 'درصدی', 'fixed' => 'مبلغ ثابت'])
                ->required()
                ->live(),

            TextInput::make('value')
                ->label(fn (callable $get): string => $get('type') === 'fixed' ? 'مبلغ (تومان)' : 'درصد')
                ->required()
                ->numeric()
                ->minValue(1)
                ->maxValue(fn (callable $get): int => $get('type') === 'percent' ? 100 : PHP_INT_MAX)
                ->formatStateUsing(fn (?int $state, callable $get): ?int => $state !== null && $get('type') === 'fixed'
                    ? Money::toToman($state)
                    : $state)
                ->dehydrateStateUsing(fn (string $state, callable $get): int => $get('type') === 'fixed'
                    ? Money::fromToman((int) $state)
                    : (int) $state),

            TextInput::make('max_redemptions')
                ->label('حداکثر دفعات استفاده')
                ->numeric()
                ->minValue(1)
                ->helperText('خالی یعنی نامحدود.'),

            DateTimePicker::make('expires_at')
                ->label('انقضا')
                ->seconds(false)
                ->helperText('خالی یعنی بدون انقضا.'),
        ]);
    }
}
