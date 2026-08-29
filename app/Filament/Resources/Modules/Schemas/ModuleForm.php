<?php

declare(strict_types=1);

namespace App\Filament\Resources\Modules\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Modules are defined in code (`PlanCatalogue`) — this screen sets the one thing that is
 * an operational decision: **is it switched on for everybody?**
 *
 * That toggle used to be an add-on price, back when a plan bought modules. Since DECISION
 * GATE 6 every module is open to every shop and a plan buys quantity instead, so there is
 * nothing to price here — and something genuinely needed a switch. ADR 0011 ships Moadian
 * as an adapter with no provider behind it; until one exists, its routes should be closed
 * for everybody, and closing them should not need a deploy.
 *
 * `code` and `is_core` come from the catalogue and are re-synced on every deploy, so they
 * are read-only: a row with no `app/Modules/<Name>` behind it is a module the application
 * cannot serve.
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

            Toggle::make('is_enabled')
                ->label('فعال برای همهٔ فروشگاه‌ها')
                ->helperText('خاموش کردن، این بخش را برای همهٔ فروشگاه‌ها می‌بندد — نه برای یک پلن خاص.'),

            Toggle::make('is_core')->label('ماژول پایه')->disabled()
                ->helperText('فروشگاه بدون آن کار نمی‌کند. فقط توضیحی است؛ جایی به آن تکیه نمی‌شود.'),
        ]);
    }
}
