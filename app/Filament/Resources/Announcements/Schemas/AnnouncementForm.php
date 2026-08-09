<?php

declare(strict_types=1);

namespace App\Filament\Resources\Announcements\Schemas;

use App\Modules\Platform\Models\Announcement;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->label('عنوان')->required()->maxLength(120)->columnSpanFull(),

            Textarea::make('body')->label('متن')->required()->rows(4)->columnSpanFull(),

            Select::make('level')
                ->label('اهمیت')
                ->options([
                    Announcement::LEVEL_INFO => 'اطلاع‌رسانی',
                    Announcement::LEVEL_WARNING => 'هشدار',
                    Announcement::LEVEL_CRITICAL => 'بحرانی',
                ])
                ->default(Announcement::LEVEL_INFO)
                ->required(),

            Select::make('tenant_id')
                ->label('فروشگاه')
                ->relationship('tenant', 'name')
                ->searchable()
                ->preload()
                ->placeholder('همه فروشگاه‌ها')
                ->helperText('خالی یعنی برای همه نمایش داده می‌شود.'),

            DateTimePicker::make('starts_at')
                ->label('شروع نمایش')
                ->seconds(false)
                ->helperText('خالی یعنی از همین حالا.'),

            DateTimePicker::make('ends_at')
                ->label('پایان نمایش')
                ->seconds(false)
                ->after('starts_at')
                ->helperText('خالی یعنی تا زمان حذف.'),
        ]);
    }
}
