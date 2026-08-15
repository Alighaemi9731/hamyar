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

    public const GROUP_TAX = 'tax';

    public const GROUP_OPERATIONS = 'operations';

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
            self::GROUP_TAX => 'مالیات',
            self::GROUP_OPERATIONS => 'عملیات',
        ];
    }

    /**
     * The report **screens**, keyed by the identifier a saved preset stores.
     *
     * Deliberately coarser than {@see reports()}: `sales.daily` and `sales.by_brand` are two
     * rows in the index and one screen with a `cut` filter, and a preset belongs to the
     * screen — «سه ماه گذشته» is a range, and the cut is just another thing the preset
     * remembers. Keying presets by catalogue row would give the same saved range three
     * entries that each restore a different tab.
     *
     * Not derived from routes, for the reason `reports()` gives about deriving anything from
     * routes: a controller existing is not the same claim as a screen being finished.
     *
     * @return array<string, string>
     */
    public static function screens(): array
    {
        return [
            'sales' => 'فروش',
            'profit' => 'سود',
            'inventory' => 'انبار',
            'technicians' => 'تعمیرات',
            'financial' => 'مالی',
            'tax' => 'مالیات',
            'operations' => 'عملیات',
        ];
    }

    /**
     * @return list<array{key: string, title: string, description: string, group: string, href: string, permission: string, gate?: string}>
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
                'gate' => 'margin',
            ],
            [
                'key' => 'profit.by_brand',
                'title' => 'سود بر اساس برند',
                'description' => 'سود هر برند در بازه، برای تصمیم خرید بعدی.',
                'group' => self::GROUP_SALES,
                'href' => '/reporting/profit?cut=brand',
                'permission' => 'reporting.view',
                'gate' => 'margin',
            ],
            [
                'key' => 'profit.per_imei',
                'title' => 'سود هر دستگاه (IMEI)',
                'description' => 'سود دقیق هر گوشی فروخته‌شده — خرید، فروش و اختلافش.',
                'group' => self::GROUP_SALES,
                'href' => '/reporting/profit?cut=imei',
                'permission' => 'reporting.view',
                'gate' => 'margin',
            ],

            [
                'key' => 'inventory.valuation',
                'title' => 'ارزش موجودی انبار',
                'description' => 'ارزش کالاها و دستگاه‌ها به بهای تمام‌شده، در تاریخ دلخواه.',
                'group' => self::GROUP_INVENTORY,
                'href' => '/reporting/inventory?cut=valuation',
                'permission' => 'reporting.view',
                'gate' => 'cost',
            ],
            [
                'key' => 'inventory.dead_stock',
                'title' => 'کالای راکد',
                'description' => 'چیزهایی که مدت‌هاست از انبار خارج نشده‌اند، با ارزششان.',
                'group' => self::GROUP_INVENTORY,
                'href' => '/reporting/inventory?cut=dead',
                'permission' => 'reporting.view',
                'gate' => 'cost',
            ],

            [
                'key' => 'repairs.technicians',
                'title' => 'کارکرد تکنسین‌ها',
                'description' => 'تحویل‌شده، روی میز، و میانگین زمان از پذیرش تا تحویل.',
                'group' => self::GROUP_REPAIRS,
                'href' => '/reporting/technicians',
                'permission' => 'reporting.view',
            ],

            /*
            | The financial three each carry the gate of the module they read from, not one
            | shared «مالی» gate. A Cashier holds `crm.view_balance` and `cheques.view` and
            | is exactly the person who chases a debt at the counter; they do not hold
            | `installments.settle_early` and have no business in the tax return. One gate
            | for the group would have to pick the loosest or the strictest, and both are
            | wrong for somebody.
            */
            [
                'key' => 'financial.aging',
                'title' => 'مانده حساب طرف‌ها (۳۰/۶۰/۹۰)',
                'description' => 'چه کسی چقدر بدهکار است، و بدهی‌اش چند روز عمر دارد.',
                'group' => self::GROUP_FINANCIAL,
                'href' => '/reporting/financial?cut=aging',
                'permission' => 'reporting.view',
                'gate' => 'balances',
            ],
            [
                'key' => 'financial.cheques',
                'title' => 'تقویم چک‌ها',
                'description' => 'سررسید هر روز — چه می‌آید، چه می‌رود، و خالصش.',
                'group' => self::GROUP_FINANCIAL,
                'href' => '/reporting/financial?cut=cheques',
                'permission' => 'reporting.view',
                'gate' => 'cheques',
            ],
            [
                'key' => 'financial.installments',
                'title' => 'دفتر اقساط',
                'description' => 'اقساط سررسیدشده بازه، وصولی هر کدام و معوق‌ها.',
                'group' => self::GROUP_FINANCIAL,
                'href' => '/reporting/financial?cut=installments',
                'permission' => 'reporting.view',
                'gate' => 'installments',
            ],

            [
                'key' => 'tax.vat_monthly',
                'title' => 'خلاصه مالیات بر ارزش افزوده',
                'description' => 'مأخذ مشمول، معاف و مالیات هر ماه شمسی — برای اظهارنامه.',
                'group' => self::GROUP_TAX,
                'href' => '/reporting/tax?cut=monthly',
                'permission' => 'reporting.view',
                'gate' => 'tax',
            ],
            [
                'key' => 'tax.by_rate',
                'title' => 'فروش بر اساس وضعیت مالیاتی',
                'description' => 'سهم هر نرخ مالیات، و سطرهای معاف در کنارشان.',
                'group' => self::GROUP_TAX,
                'href' => '/reporting/tax?cut=rate',
                'permission' => 'reporting.view',
                'gate' => 'tax',
            ],

            [
                'key' => 'operations.sms',
                'title' => 'مصرف پیامک',
                'description' => 'هر قالب چند پیامک و چند بخش فرستاده، و چقدر خرج برداشته.',
                'group' => self::GROUP_OPERATIONS,
                'href' => '/reporting/operations',
                'permission' => 'reporting.view',
                'gate' => 'messaging',
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

            /*
            | The row's gate, resolved by the same `ReportAccess` its screen calls. That
            | shared predicate is the whole guarantee: an index that disagrees with its
            | screens lists rows that 403 when clicked, which is worse than no index.
            */
            if (! ReportAccess::allows($user, $report['gate'] ?? null)) {
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
