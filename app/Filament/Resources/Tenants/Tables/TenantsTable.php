<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenants\Tables;

use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\ImpersonationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('فروشگاه')->searchable(),

                TextColumn::make('domains.hostname')
                    ->label('نشانی')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                TextColumn::make('subscription_status')
                    ->label('اشتراک')
                    ->badge()
                    ->state(fn (Tenant $record): string => self::statusFor($record))
                    ->color(fn (string $state): string => match ($state) {
                        'فعال' => 'success',
                        'آزمایشی' => 'info',
                        'معوق' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')->label('تاریخ ثبت')->dateTime('Y/m/d')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options([
                        Subscription::STATUS_ACTIVE => 'فعال',
                        Subscription::STATUS_TRIALING => 'آزمایشی',
                        Subscription::STATUS_PAST_DUE => 'معوق',
                        Subscription::STATUS_CANCELED => 'لغوشده',
                    ])
                    ->query(fn ($query, array $data) => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('subscriptions', fn ($q) => $q->where('status', $data['value']))),
            ])
            ->recordActions([
                EditAction::make()->label('ویرایش'),

                Action::make('impersonate')
                    ->label('ورود به عنوان مالک')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('warning')
                    // Two-step on purpose. Impersonation gives a staff member the run of
                    // a real shop's data, so it should never be one stray click away,
                    // and the reason is required because "why did you enter my shop"
                    // deserves an answer that exists before the question.
                    ->requiresConfirmation()
                    ->modalHeading('ورود به حساب مالک فروشگاه')
                    ->modalDescription('این اقدام ثبت و ممیزی می‌شود و مالک فروشگاه می‌تواند آن را ببیند.')
                    ->schema([
                        \Filament\Forms\Components\Textarea::make('reason')
                            ->label('دلیل')
                            ->required()
                            ->minLength(10)
                            ->maxLength(500)
                            ->helperText('مثلاً شماره تیکت پشتیبانی.'),
                    ])
                    ->action(function (Tenant $record, array $data): mixed {
                        $url = app(ImpersonationService::class)->start(
                            $record,
                            (string) $data['reason']
                        );

                        if ($url === null) {
                            Notification::make()
                                ->danger()
                                ->title('این فروشگاه مالک فعالی ندارد.')
                                ->send();

                            return null;
                        }

                        return redirect()->away($url);
                    }),
            ])
            ->toolbarActions([]);
    }

    private static function statusFor(Tenant $tenant): string
    {
        $subscription = $tenant->subscriptions()->orderByDesc('id')->first();

        return match ($subscription?->status) {
            Subscription::STATUS_ACTIVE => 'فعال',
            Subscription::STATUS_TRIALING => 'آزمایشی',
            Subscription::STATUS_PAST_DUE => 'معوق',
            Subscription::STATUS_CANCELED => 'لغوشده',
            default => 'بدون اشتراک',
        };
    }
}
