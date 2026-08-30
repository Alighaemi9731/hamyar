<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenants\Schemas;

use App\Modules\Platform\Models\Tenant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

            /*
            | The real switch, and it is a status rather than a boolean.
            |
            | This used to be `Toggle::make('is_active')`, which promised to cut off a
            | shop's access immediately and did nothing at all: `tenants` has no
            | `is_active` column and `EditTenant` had no handler for it. A control that
            | claims to suspend a shop and silently does not is worse than no control —
            | somebody would have used it in an incident and believed it.
            |
            | `status` is what `Tenant::isUsable()` actually reads, and what `ResolveTenant`
            | refuses on. `trialing` stays in the list because rows predating DECISION
            | GATE 6 still carry it, and hiding a value the data contains would make the
            | form lie in the other direction.
            */
            Select::make('status')
                ->label('وضعیت')
                ->options([
                    Tenant::STATUS_ACTIVE => 'فعال',
                    Tenant::STATUS_TRIALING => 'آزمایشی (قدیمی)',
                    Tenant::STATUS_SUSPENDED => 'معلق — ورود بسته می‌شود',
                    Tenant::STATUS_ARCHIVED => 'بایگانی — ورود بسته می‌شود',
                ])
                ->required()
                ->helperText('«معلق» و «بایگانی» دسترسی همهٔ کاربران فروشگاه را فوراً می‌بندند.'),
        ]);
    }
}
