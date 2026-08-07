<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Models\PlanLimit;
use App\Support\Money;

/**
 * The proposed plan and module catalogue — the seed of the pricing table.
 *
 * **Prices and limits are PROPOSED and need sign-off at DECISION GATE 2.** They come
 * from the business plan (docs/01-master-plan-fa.md §7); the shape is settled, the
 * numbers are not. Everything is integer RIAL (golden rule 2), written via
 * `Money::fromToman()` because Iranian shops quote in toman and a price typed in the
 * wrong unit is a silent factor-of-ten billing error.
 */
final class PlanCatalogue
{
    /**
     * Modules, in nav order. `code` matches `app/Modules/<Name>` lowercased and is the
     * same string used by the Pennant feature and the route middleware — one spelling,
     * so a typo fails loudly instead of leaving a module quietly ungated.
     *
     * @return list<array{code: string, name_fa: string, is_core: bool, is_addonable: bool, addon_toman: int|null}>
     */
    public static function modules(): array
    {
        return [
            // Core: every plan includes these and no shop can turn them off. A POS
            // product without catalogue, stock, customers or sales is not a product.
            ['code' => 'catalog', 'name_fa' => 'کالا', 'is_core' => true, 'is_addonable' => false, 'addon_toman' => null],
            ['code' => 'inventory', 'name_fa' => 'انبار', 'is_core' => true, 'is_addonable' => false, 'addon_toman' => null],
            ['code' => 'sales', 'name_fa' => 'فروش و صندوق', 'is_core' => true, 'is_addonable' => false, 'addon_toman' => null],
            ['code' => 'crm', 'name_fa' => 'مشتریان', 'is_core' => true, 'is_addonable' => false, 'addon_toman' => null],
            ['code' => 'purchasing', 'name_fa' => 'خرید', 'is_core' => true, 'is_addonable' => false, 'addon_toman' => null],
            ['code' => 'settings', 'name_fa' => 'تنظیمات', 'is_core' => true, 'is_addonable' => false, 'addon_toman' => null],
            ['code' => 'files', 'name_fa' => 'فایل‌ها', 'is_core' => true, 'is_addonable' => false, 'addon_toman' => null],
            ['code' => 'reporting', 'name_fa' => 'گزارش‌ها', 'is_core' => true, 'is_addonable' => false, 'addon_toman' => null],

            // Sellable separately — the ones shops actually ask for one at a time.
            ['code' => 'repairs', 'name_fa' => 'تعمیرات', 'is_core' => false, 'is_addonable' => true, 'addon_toman' => 150_000],
            ['code' => 'installments', 'name_fa' => 'اقساط', 'is_core' => false, 'is_addonable' => true, 'addon_toman' => 100_000],
            ['code' => 'cheques', 'name_fa' => 'چک‌ها', 'is_core' => false, 'is_addonable' => true, 'addon_toman' => 80_000],
            ['code' => 'treasury', 'name_fa' => 'خزانه‌داری', 'is_core' => false, 'is_addonable' => true, 'addon_toman' => 100_000],
            ['code' => 'messaging', 'name_fa' => 'پیامک', 'is_core' => false, 'is_addonable' => true, 'addon_toman' => 80_000],
            ['code' => 'storefront', 'name_fa' => 'ویترین', 'is_core' => false, 'is_addonable' => true, 'addon_toman' => 80_000],
            ['code' => 'moadian', 'name_fa' => 'سامانه مودیان', 'is_core' => false, 'is_addonable' => true, 'addon_toman' => 150_000],
            ['code' => 'hamta', 'name_fa' => 'همتا', 'is_core' => false, 'is_addonable' => true, 'addon_toman' => 50_000],
        ];
    }

    /**
     * Three plans. `null` in a limit means unlimited.
     *
     * @return list<array{code: string, name_fa: string, tagline_fa: string, price_toman: int, modules: list<string>, limits: array<string, int|null>}>
     */
    public static function plans(): array
    {
        $core = array_values(array_map(
            static fn (array $m): string => $m['code'],
            array_filter(self::modules(), static fn (array $m): bool => $m['is_core'])
        ));

        return [
            [
                'code' => 'basic',
                'name_fa' => 'پایه',
                'tagline_fa' => 'برای مغازه‌های تک‌شعبه‌ای که تازه شروع کرده‌اند',
                'price_toman' => 290_000,
                'modules' => $core,
                'limits' => [
                    PlanLimit::USERS => 2,
                    PlanLimit::BRANCHES => 1,
                    PlanLimit::INVOICES_PER_MONTH => 500,
                    PlanLimit::STORAGE_MB => 1_024,
                    PlanLimit::SMS_CREDIT_BONUS => 0,
                ],
            ],
            [
                'code' => 'pro',
                'name_fa' => 'حرفه‌ای',
                'tagline_fa' => 'تعمیرات، اقساط و چک — انتخاب بیشتر فروشگاه‌ها',
                'price_toman' => 590_000,
                'modules' => [...$core, 'repairs', 'cheques', 'installments', 'treasury', 'messaging'],
                'limits' => [
                    PlanLimit::USERS => 5,
                    PlanLimit::BRANCHES => 2,
                    PlanLimit::INVOICES_PER_MONTH => 5_000,
                    PlanLimit::STORAGE_MB => 5_120,
                    PlanLimit::SMS_CREDIT_BONUS => 500,
                ],
            ],
            [
                'code' => 'enterprise',
                'name_fa' => 'سازمانی',
                'tagline_fa' => 'چندشعبه، مودیان و ویترین آنلاین',
                'price_toman' => 1_190_000,
                'modules' => array_values(array_map(
                    static fn (array $m): string => $m['code'],
                    self::modules()
                )),
                'limits' => [
                    PlanLimit::USERS => 15,
                    PlanLimit::BRANCHES => 5,
                    // Unlimited. Null, not 0 — 0 reads as "none".
                    PlanLimit::INVOICES_PER_MONTH => null,
                    PlanLimit::STORAGE_MB => 20_480,
                    PlanLimit::SMS_CREDIT_BONUS => 2_000,
                ],
            ],
        ];
    }

    public static function rial(int $toman): int
    {
        return Money::fromToman($toman);
    }
}
