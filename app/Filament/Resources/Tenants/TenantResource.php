<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenants;

use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Filament\Resources\Tenants\Schemas\TenantForm;
use App\Filament\Resources\Tenants\Tables\TenantsTable;
use App\Modules\Platform\Models\Tenant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $navigationLabel = 'فروشگاه‌ها';

    protected static ?string $modelLabel = 'فروشگاه';

    protected static ?string $pluralModelLabel = 'فروشگاه‌ها';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return TenantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function canCreate(): bool
    {
        // Shops sign themselves up through onboarding, which provisions roles, a trial
        // and a domain in one transaction. A bare row created here would have none of
        // that and would look like a broken tenant.
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        unset($record);

        // Deactivate instead. A tenant cascade-deletes every shop record it owns.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
