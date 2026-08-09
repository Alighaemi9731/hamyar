<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenants\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Support-side tenant editing.
 *
 * Narrow on purpose: the shop's own settings belong to the shop. What staff need is the
 * ability to fix a mistyped name and to suspend an account, and nothing else. The
 * subdomain is not editable here — it is a `domains` row that shops link to and print on
 * receipts, so changing it is a migration, not a form field (golden rule 1b).
 */
final class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('نام فروشگاه')->required()->maxLength(80),

            Toggle::make('is_active')
                ->label('فعال')
                ->helperText('غیرفعال کردن، دسترسی همه کاربران فروشگاه را فوراً قطع می‌کند.'),
        ]);
    }
}
