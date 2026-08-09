<?php

declare(strict_types=1);

namespace App\Filament\Resources\Plans\Schemas;

use App\Modules\Platform\Models\Module;
use App\Modules\Platform\Models\PlanLimit;
use App\Support\Money;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Editing a plan — the screen Gate 2 item 1 exists for.
 *
 * Prices are provisional business data, so they live here rather than in code.
 * `PlanCatalogueSeeder` seeds them on a fresh install and never overwrites them again,
 * which is what makes editing here safe.
 *
 * The price field takes **toman** and stores **rial**. Iranian shops quote in toman and
 * a staff member typing 590000 means ۵۹۰,۰۰۰ تومان; storing that as rial would undercharge
 * by a factor of ten and nobody would notice until the month closed.
 */
final class PlanForm
{
    /**
     * Module id => code, shown under each checkbox.
     *
     * Staff match these codes against route middleware and feature flags when working
     * out why a shop cannot see something, so the raw code earns its place on screen.
     *
     * @return array<int, string>
     */
    private static function moduleCodes(): array
    {
        $codes = [];

        foreach (Module::query()->get(['id', 'code']) as $module) {
            $codes[$module->id] = $module->code;
        }

        return $codes;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('مشخصات پلن')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('کد')
                        ->required()
                        ->alphaDash()
                        ->maxLength(30)
                        // The code is referenced by TrialPolicy and by tests. Renaming it
                        // on a live plan silently detaches both.
                        ->disabledOn('edit')
                        ->helperText('پس از ساخت قابل تغییر نیست.'),

                    TextInput::make('name_fa')
                        ->label('نام')
                        ->required()
                        ->maxLength(60),

                    Textarea::make('tagline_fa')
                        ->label('توضیح کوتاه')
                        ->rows(2)
                        ->columnSpanFull()
                        ->maxLength(160),

                    TextInput::make('price')
                        ->label('قیمت ماهانه (تومان)')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step(1000)
                        ->suffix('تومان')
                        // Stored as integer rial (golden rule 2), shown as toman.
                        ->formatStateUsing(fn (?int $state): ?int => $state === null ? null : Money::toToman($state))
                        ->dehydrateStateUsing(fn (?string $state): int => Money::fromToman((int) $state)),

                    Select::make('interval')
                        ->label('دوره')
                        ->options(['month' => 'ماهانه', 'quarter' => 'سه‌ماهه', 'year' => 'سالانه'])
                        ->default('month')
                        ->required(),

                    TextInput::make('trial_days')
                        ->label('روزهای آزمایشی')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(90)
                        ->required(),

                    Toggle::make('is_public')
                        ->label('نمایش در صفحه قیمت‌ها')
                        ->helperText('پلن خصوصی فقط با تخصیص دستی قابل خرید است.'),
                ]),

            Section::make('ماژول‌ها')
                ->description('ماژول‌های پایه در همه پلن‌ها هستند و قابل حذف نیستند.')
                ->schema([
                    CheckboxList::make('modules')
                        ->label('')
                        ->relationship('modules', 'name_fa')
                        ->columns(3)
                        ->bulkToggleable()
                        // Shows the module CODE under each name: staff match these
                        // against route middleware and Pennant flags when debugging why
                        // a shop cannot see something.
                        ->descriptions(self::moduleCodes()),
                ]),

            Section::make('محدودیت‌ها')
                ->description('خالی گذاشتن مقدار یعنی نامحدود.')
                ->schema([
                    Repeater::make('limits')
                        ->label('')
                        ->relationship('limits')
                        ->columns(2)
                        ->schema([
                            Select::make('key')
                                ->label('محدودیت')
                                ->options(array_combine(PlanLimit::keys(), array_map(
                                    static fn (string $key): string => PlanLimit::labelFor($key),
                                    PlanLimit::keys()
                                )))
                                ->required()
                                ->distinct(),

                            TextInput::make('value')
                                ->label('مقدار')
                                ->numeric()
                                ->minValue(0)
                                ->helperText('خالی = نامحدود'),
                        ])
                        ->addActionLabel('افزودن محدودیت'),
                ]),
        ]);
    }
}
