<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionForm;
use App\Filament\Resources\Subscriptions\Tables\SubscriptionsTable;
use App\Modules\Platform\Models\Subscription;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static ?string $navigationLabel = 'اشتراک‌ها';

    protected static ?string $modelLabel = 'اشتراک';

    protected static ?string $pluralModelLabel = 'اشتراک‌ها';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return SubscriptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubscriptionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function canCreate(): bool
    {
        // A subscription is created by paying for one, never by hand — see
        // SubscriptionsTable for why.
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        unset($record);

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
            'index' => ListSubscriptions::route('/'),
        ];
    }
}
