<?php

declare(strict_types=1);

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Filament\Resources\Plans\Schemas\PlanForm;
use App\Filament\Resources\Plans\Tables\PlansTable;
use App\Modules\Platform\Models\Plan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    /*
    | Pinned to the id on purpose. `Plan::getRouteKeyName()` returns `code`, because the
    | shop-facing `POST /billing/subscribe/{plan}` is addressed by code — but the panel's
    | own URLs were never part of that decision, and letting a model-level change silently
    | rewrite every `/admin/plans/{id}/edit` link is how a fix acquires a blast radius
    | nobody asked for. The panel keeps the ids it has always had.
    */
    protected static ?string $recordRouteKeyName = 'id';

    protected static ?string $navigationLabel = 'پلن‌ها';

    protected static ?string $modelLabel = 'پلن';

    protected static ?string $pluralModelLabel = 'پلن‌ها';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }

    /**
     * Plans are never deleted — see PlansTable for why.
     */
    public static function canDelete(Model $record): bool
    {
        unset($record);

        return false;
    }
}
