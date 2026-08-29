<?php

declare(strict_types=1);

namespace App\Filament\Resources\Plans\Schemas;

use App\Modules\Platform\Support\PlanCatalogue;
use App\Support\Money;
use App\Support\Quota\MetricRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
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
     * One field per registered metric, grouped by the module that owns it.
     *
     * Built from `MetricRegistry` rather than a hard-coded list, which is what makes
     * shipping a metered action a change in one module: Sales registers
     * `sales.invoices`, and it appears here, on the pricing page and in the usage screen
     * without Platform being edited (golden rule 6).
     *
     * The consequence to know: a metric with no value here is **unlimited** on this plan.
     * That is deliberate — a module can ship a metric without a data migration — so the
     * fields carry a hint saying exactly that, and `quota:audit` lists the gaps. A quota
     * that silently does nothing is the failure this whole phase exists to end, and an
     * empty box that reads as "not set yet" would be the same failure wearing a form.
     *
     * @return list<\Filament\Schemas\Components\Component>
     */
    private static function limitFields(): array
    {
        $registry = app(MetricRegistry::class);
        $sections = [];

        foreach ($registry->byModule() as $module => $metrics) {
            $fields = [];

            foreach ($metrics as $metric) {
                $fields[] = TextInput::make(self::fieldFor($metric->key))
                    ->label($metric->labelFa)
                    ->numeric()
                    ->minValue(0)
                    ->suffix($metric->window->labelFa())
                    ->helperText("{$metric->key} — خالی = نامحدود");
            }

            $sections[] = Fieldset::make(self::moduleLabel($module))
                ->columns(3)
                ->schema($fields);
        }

        return $sections;
    }

    /**
     * The form field name for a metric key.
     *
     * A field name may not contain a dot: Filament reads dots as nested state, so
     * `limits.sales.invoices` would become `limits → sales → invoices` and every metric
     * would collide with the module prefix it shares. Every metric key has a dot in it by
     * construction, so the translation is mandatory rather than cosmetic, and it lives
     * here so the pages that fill and save the form cannot disagree with the form about
     * what a field is called.
     */
    public static function fieldFor(string $metricKey): string
    {
        return 'quota_'.str_replace('.', '__', $metricKey);
    }

    /**
     * The Persian name of a module, for the section heading.
     *
     * Falls back to the raw code: a module the catalogue does not name is a bug worth
     * seeing on screen rather than a heading that silently goes missing.
     */
    private static function moduleLabel(string $code): string
    {
        foreach (PlanCatalogue::modules() as $module) {
            if ($module['code'] === $code) {
                return $module['name_fa'];
            }
        }

        return $code;
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

            Section::make('سهمیه‌ها')
                ->description('خالی گذاشتن یعنی نامحدود. سهمیه‌های ماهانه اول هر ماه شمسی از نو پر می‌شوند.')
                ->schema(self::limitFields()),
        ]);
    }
}
