<?php

declare(strict_types=1);

namespace App\Filament\Resources\Modules;

use App\Filament\Resources\Modules\Pages\EditModule;
use App\Filament\Resources\Modules\Pages\ListModules;
use App\Filament\Resources\Modules\Schemas\ModuleForm;
use App\Filament\Resources\Modules\Tables\ModulesTable;
use App\Modules\Platform\Models\Module;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class ModuleResource extends Resource
{
    protected static ?string $model = Module::class;

    protected static ?string $navigationLabel = 'ماژول‌ها';

    protected static ?string $modelLabel = 'ماژول';

    protected static ?string $pluralModelLabel = 'ماژول‌ها';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ModuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ModulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        unset($record);

        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModules::route('/'),
            'edit' => EditModule::route('/{record}/edit'),
        ];
    }
}
