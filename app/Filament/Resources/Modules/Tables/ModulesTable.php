<?php

declare(strict_types=1);

namespace App\Filament\Resources\Modules\Tables;

use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ModulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('name_fa')->label('نام')->searchable(),
                TextColumn::make('code')->label('کد')->badge()->color('gray')->searchable(),
                IconColumn::make('is_core')->label('پایه')->boolean(),
                IconColumn::make('is_addonable')->label('افزودنی')->boolean(),

                TextColumn::make('addon_price')
                    ->label('قیمت افزودنی')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Money::formatWithUnit($state)),
            ])
            ->recordActions([EditAction::make()->label('ویرایش')])
            // Modules come from code. Creating or deleting one here would produce a row
            // with no `app/Modules/<Name>` behind it — a plan could then grant a module
            // that does not exist.
            ->toolbarActions([]);
    }
}
