<?php

declare(strict_types=1);

namespace App\Filament\Resources\Announcements\Tables;

use App\Modules\Platform\Models\Announcement;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('title')->label('عنوان')->searchable()->wrap(),

                TextColumn::make('level')
                    ->label('اهمیت')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Announcement::LEVEL_CRITICAL => 'بحرانی',
                        Announcement::LEVEL_WARNING => 'هشدار',
                        default => 'اطلاع‌رسانی',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Announcement::LEVEL_CRITICAL => 'danger',
                        Announcement::LEVEL_WARNING => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('tenant.name')->label('فروشگاه')->placeholder('همه'),

                TextColumn::make('starts_at')->label('شروع')->dateTime('Y/m/d H:i')->placeholder('فوری'),
                TextColumn::make('ends_at')->label('پایان')->dateTime('Y/m/d H:i')->placeholder('بدون پایان'),
            ])
            ->recordActions([
                EditAction::make()->label('ویرایش'),
                DeleteAction::make()->label('حذف'),
            ])
            ->toolbarActions([]);
    }
}
