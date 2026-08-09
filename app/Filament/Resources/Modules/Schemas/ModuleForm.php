<?php

declare(strict_types=1);

namespace App\Filament\Resources\Modules\Schemas;

use App\Support\Money;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Modules are defined in code (`PlanCatalogue`) — this screen only sets the ONE thing
 * that is a business decision: what an add-on costs.
 *
 * `code`, `is_core` and `is_addonable` are re-synced from the catalogue on every deploy,
 * so they are read-only here. Letting staff mark a module addonable in the panel would
 * let them sell something the application cannot actually deliver separately.
 */
final class ModuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name_fa')->label('نام')->required()->maxLength(60),

            TextInput::make('code')
                ->label('کد')
                ->disabled()
                ->helperText('از PlanCatalogue می‌آید و در هر استقرار همگام می‌شود.'),

            TextInput::make('addon_price')
                ->label('قیمت افزودنی (تومان)')
                ->numeric()
                ->minValue(0)
                ->step(1000)
                ->suffix('تومان')
                ->helperText('خالی یعنی جداگانه فروخته نمی‌شود.')
                ->formatStateUsing(fn (?int $state): ?int => $state === null ? null : Money::toToman($state))
                ->dehydrateStateUsing(fn (?string $state): ?int => $state === null || $state === ''
                    ? null
                    : Money::fromToman((int) $state)),

            Toggle::make('is_core')->label('ماژول پایه')->disabled(),
            Toggle::make('is_addonable')->label('قابل فروش جداگانه')->disabled(),
        ]);
    }
}
