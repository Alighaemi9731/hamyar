<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Support\ReportAccess;

/**
 * The list of reports, and who may open each one.
 *
 * ## Only reports that exist are listed
 *
 * The spec enumerates thirty-odd; this returns the ones with a screen behind them.
 * A greyed-out «به‌زودی» row is a promise the product has not made, and a shop that
 * clicks one and gets nothing learns that the report index cannot be trusted — after
 * which they stop reading it and ask somebody to run a query instead.
 *
 * Adding a report is therefore two edits, not one: the screen, and a row here. That is
 * deliberate. The alternative — deriving the index from routes — would list every report
 * the moment its controller existed, including the half-finished ones.
 *
 * ## The group is how a shopkeeper files it, not how the code is organised
 *
 * «فروش» holds the sales cuts wherever their queries live. Grouping by module would put
 * the profit report under Sales and the P&L under Treasury, which is a distinction only
 * a developer makes.
 */
final class ReportCatalogue
{
    public const GROUP_SALES = 'sales';

    public const GROUP_INVENTORY = 'inventory';

    public const GROUP_REPAIRS = 'repairs';

    public const GROUP_FINANCIAL = 'financial';

    /**
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            self::GROUP_SALES => 'فروش و سود',
            self::GROUP_INVENTORY => 'انبار',
            self::GROUP_REPAIRS => 'تعمیرات',
            self::GROUP_FINANCIAL => 'مالی',
        ];
    }

    /**
     * @return list<array{key: string, title: string, description: string, group: string, href: string, permission: string, needs_margin?: bool}>
     */
    public static function reports(): array
    {
        return [
            [
                'key' => 'sales.daily',
                'title' => 'فروش روزانه',
                'description' => 'فروش هر روز بازه، با تعداد فاکتور و نمودار.',
                'group' => self::GROUP_SALES,
                'href' => '/reporting/sales?cut=daily',
                'permission' => 'reporting.view',
            ],
            [
                'key' => 'sales.monthly',
                'title' => 'فروش ماهانه',
                'description' => 'هر ماه شمسی یک سطر — شکل سال، نه شلوغی روزها.',
                'group' => self::GROUP_SALES,
                'href' => '/reporting/sales?cut=monthly',
                'permission' => 'reporting.view',
            ],
            [
                'key' => 'sales.by_product',
                'title' => 'فروش بر اساس کالا',
                'description' => 'پرفروش‌ترین کالاها در بازه، بر اساس مبلغ فروش.',
                'group' => self::GROUP_SALES,
                'href' => '/reporting/sales?cut=product',
                'permission' => 'reporting.view',
            ],
            [
                'key' => 'sales.by_brand',
                'title' => 'فروش بر اساس برند',
                'description' => 'سهم هر برند از فروش بازه — برای تصمیم خرید بعدی.',
                'group' => self::GROUP_SALES,
                'href' => '/reporting/sales?cut=brand',
                'permission' => 'reporting.view',
            ],
            [
                'key' => 'sales.by_salesperson',
                'title' => 'فروش بر اساس فروشنده',
                'description' => 'سهم هر فروشنده از فروش بازه — شروع گفتگوی پورسانت.',
                'group' => self::GROUP_SALES,
                'href' => '/reporting/sales?cut=salesperson',
                'permission' => 'reporting.view',
            ],

            /*
            | The profit cuts carry `needs_margin`, and it is not a second permission —
            | it is `ReportAccess`, the one predicate the dashboard and the sales report
            | already ask. A row listed here that 403s when clicked is worse than no row,
            | and the only way to guarantee they agree is for both to ask the same thing.
            */
            [
                'key' => 'profit.by_product',
                'title' => 'سود بر اساس کالا',
                'description' => 'سودآورترین کالاها — نه لزوماً پرفروش‌ترین‌ها.',
                'group' => self::GROUP_SALES,
                'href' => '/reporting/profit?cut=product',
                'permission' => 'reporting.view',
                'needs_margin' => true,
            ],
            [
                'key' => 'profit.by_brand',
                'title' => 'سود بر اساس برند',
                'description' => 'سود هر برند در بازه، برای تصمیم خرید بعدی.',
                'group' => self::GROUP_SALES,
                'href' => '/reporting/profit?cut=brand',
                'permission' => 'reporting.view',
                'needs_margin' => true,
            ],
            [
                'key' => 'profit.per_imei',
                'title' => 'سود هر دستگاه (IMEI)',
                'description' => 'سود دقیق هر گوشی فروخته‌شده — خرید، فروش و اختلافش.',
                'group' => self::GROUP_SALES,
                'href' => '/reporting/profit?cut=imei',
                'permission' => 'reporting.view',
                'needs_margin' => true,
            ],
        ];
    }

    /**
     * The catalogue this user may actually open, grouped for the index page.
     *
     * Grouping happens after filtering, so a group whose every report is out of reach
     * does not render as an empty heading — an empty «مالی» section tells a warehouse
     * keeper only that there is something they cannot see.
     *
     * @return list<array{key: string, label: string, reports: list<array{key: string, title: string, description: string, href: string}>}>
     */
    public static function visibleTo(?User $user): array
    {
        $byGroup = [];

        foreach (self::reports() as $report) {
            if (! $user instanceof User || ! $user->can($report['permission'])) {
                continue;
            }

            if (($report['needs_margin'] ?? false) && ! ReportAccess::showsMargin($user)) {
                continue;
            }

            $byGroup[$report['group']][] = [
                'key' => $report['key'],
                'title' => $report['title'],
                'description' => $report['description'],
                'href' => $report['href'],
            ];
        }

        $grouped = [];

        foreach (self::groups() as $key => $label) {
            if (($byGroup[$key] ?? []) === []) {
                continue;
            }

            $grouped[] = ['key' => $key, 'label' => $label, 'reports' => $byGroup[$key]];
        }

        return $grouped;
    }
}
