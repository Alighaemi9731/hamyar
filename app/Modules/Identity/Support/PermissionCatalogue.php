<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * The `module.action` permission catalogue and the default role definitions.
 *
 * Single source of truth: the seeder, the permission-matrix UI and the policy names
 * all read from here, so adding a capability is one edit.
 *
 * Permissions are **central** (one catalogue for the whole product). Roles are
 * **per-tenant**, seeded at onboarding, so a shop can genuinely change what its
 * Salesperson may do — the most-requested example being `inventory.view_cost`, which
 * some owners want their staff to see and most emphatically do not.
 */
final class PermissionCatalogue
{
    /**
     * module => [action => Persian description]
     *
     * Only capabilities that exist or are imminent are listed. A permission nobody
     * checks is worse than none: it reads as protection that is not there.
     *
     * @return array<string, array<string, string>>
     */
    public static function permissions(): array
    {
        return [
            'users' => [
                'view' => 'مشاهده کاربران',
                'invite' => 'دعوت کاربر جدید',
                'update' => 'ویرایش کاربر',
                'deactivate' => 'غیرفعال‌کردن کاربر',
                'assign_roles' => 'تخصیص نقش',
            ],
            'roles' => [
                'view' => 'مشاهده نقش‌ها',
                'manage' => 'ساخت و ویرایش نقش‌ها',
            ],
            'settings' => [
                'view' => 'مشاهده تنظیمات فروشگاه',
                'update' => 'ویرایش تنظیمات فروشگاه',
            ],
            'billing' => [
                'view' => 'مشاهده اشتراک و صورتحساب',
                'manage' => 'خرید و ارتقای پلن',
            ],
            'activity' => [
                'view' => 'مشاهده گزارش فعالیت کاربران',
            ],
            'catalog' => [
                'view' => 'مشاهده کالاها',
                'create' => 'ثبت کالا',
                'update' => 'ویرایش کالا',
                'delete' => 'حذف کالا',
                'manage_prices' => 'تغییر قیمت‌ها',
            ],
            'inventory' => [
                'view' => 'مشاهده انبار',
                'adjust' => 'تعدیل موجودی و انبارگردانی',
                'transfer' => 'حواله بین انبارها',
                'view_cost' => 'مشاهده بهای خرید',
            ],
            'purchasing' => [
                'view' => 'مشاهده فاکتورهای خرید',
                'create' => 'ثبت خرید',
                'return' => 'برگشت از خرید',
            ],
            'sales' => [
                'view' => 'مشاهده فاکتورهای فروش',
                'create' => 'ثبت فاکتور فروش',
                'void' => 'ابطال فاکتور',
                'return' => 'برگشت از فروش',
                'discount' => 'اعمال تخفیف دستی',
                'override_price' => 'تغییر قیمت هنگام فروش',
                'view_profit' => 'مشاهده سود فاکتور',
                // The opt-in the Salesperson boundary needs an escape hatch for. Granting
                // it lets a seller see the commission on THEIR OWN sales and nobody
                // else's — and, because commission is a known percentage of margin, it
                // necessarily lets them work out the margin on those sales too. That is
                // the trade, and it is the shop's to make: some owners run open-book
                // sales floors, most do not. Off by default (Gate 3).
                'view_own_commission' => 'مشاهده پورسانت فروش خودش',
            ],
            'repairs' => [
                'view' => 'مشاهده تعمیرات',
                'create' => 'ثبت قبض پذیرش',
                'update' => 'ویرایش تیکت تعمیر',
                'assign' => 'تخصیص تعمیرکار',
                'approve' => 'ثبت تأیید مشتری',
                'deliver' => 'تحویل دستگاه',
                'reveal_passcode' => 'مشاهده رمز دستگاه مشتری',
            ],
            'crm' => [
                'view' => 'مشاهده مشتریان',
                'create' => 'ثبت مشتری',
                'update' => 'ویرایش مشتری',
                'view_balance' => 'مشاهده مانده حساب',
                'manage_loyalty' => 'تغییر دستی امتیاز وفاداری',
                'import' => 'ورود گروهی از اکسل',
            ],
            'treasury' => [
                'view' => 'مشاهده حساب‌ها',
                'receive' => 'ثبت دریافت',
                'pay' => 'ثبت پرداخت',
                'transfer' => 'انتقال بین حساب‌ها',
                'close_day' => 'بستن صندوق روزانه',
            ],
            'cheques' => [
                'view' => 'مشاهده چک‌ها',
                'manage' => 'ثبت و تغییر وضعیت چک',
            ],
            'installments' => [
                'view' => 'مشاهده اقساط',
                'collect' => 'وصول قسط',
                'settle_early' => 'تسویه زودهنگام',
            ],
            'messaging' => [
                'view' => 'مشاهده پیامک‌ها',
                'send' => 'ارسال پیامک',
                'campaign' => 'اجرای کمپین پیامکی',
            ],
            'reporting' => [
                'view' => 'مشاهده گزارش‌ها',
                'view_financial' => 'مشاهده گزارش‌های مالی و سود',
                'export' => 'خروجی اکسل',
            ],
        ];
    }

    /**
     * Flat list of `module.action` names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (self::permissions() as $module => $actions) {
            foreach (array_keys($actions) as $action) {
                $names[] = "{$module}.{$action}";
            }
        }

        return $names;
    }

    /**
     * The seven default roles.
     *
     * `*` means every permission. The split reflects how an Iranian phone shop
     * actually divides work, and two boundaries are deliberate rather than cosmetic:
     *
     * · **Cost and profit are separated from selling.** A Salesperson can sell but
     *   cannot see `inventory.view_cost` or `sales.view_profit`. Margins are the most
     *   commercially sensitive thing in the shop and staff turnover is high.
     *   `sales.view_own_commission` is the narrow opt-in for shops that want sellers to
     *   see what they earned: it exposes one figure, on that seller's own invoices,
     *   and nothing about anybody else's sales. Still off by default, because it does
     *   reveal the margin on those invoices to anyone who knows their own rate.
     * · **Device passcodes are their own permission.** `repairs.reveal_passcode` sits
     *   with technicians only, and every use is audited.
     *
     * @return array<string, array{name_fa: string, permissions: list<string>|array{0: '*'}}>
     */
    public static function roles(): array
    {
        return [
            'Owner' => [
                'name_fa' => 'مالک',
                'permissions' => ['*'],
            ],

            'Manager' => [
                'name_fa' => 'مدیر',
                // Everything operational. No billing: buying and cancelling plans is
                // the owner's decision, not a manager's.
                'permissions' => array_values(array_filter(
                    self::names(),
                    static fn (string $p): bool => ! str_starts_with($p, 'billing.'),
                )),
            ],

            'Cashier' => [
                'name_fa' => 'صندوق‌دار',
                'permissions' => [
                    'sales.view', 'sales.create', 'sales.return',
                    'crm.view', 'crm.create', 'crm.view_balance',
                    'treasury.view', 'treasury.receive', 'treasury.pay', 'treasury.close_day',
                    'cheques.view', 'cheques.manage',
                    'installments.view', 'installments.collect',
                    'catalog.view', 'inventory.view',
                    'reporting.view',
                ],
            ],

            'Salesperson' => [
                'name_fa' => 'فروشنده',
                'permissions' => [
                    'sales.view', 'sales.create',
                    // Writing an instalment contract is a counter act — it happens while
                    // the customer is standing there agreeing terms — so it rides on
                    // `sales.create`. Reading one back has to come with it, or the person
                    // who just wrote the contract cannot open it to print it. Collecting
                    // instalments is a different job and stays with Cashier/Accountant.
                    'installments.view',
                    'crm.view', 'crm.create', 'crm.update',
                    'catalog.view', 'inventory.view',
                    'repairs.view', 'repairs.create',
                ],
            ],

            'Technician' => [
                'name_fa' => 'تعمیرکار',
                'permissions' => [
                    'repairs.view', 'repairs.create', 'repairs.update',
                    'repairs.approve', 'repairs.deliver', 'repairs.reveal_passcode',
                    'crm.view',
                    'catalog.view', 'inventory.view',
                ],
            ],

            'Accountant' => [
                'name_fa' => 'حسابدار',
                'permissions' => [
                    'treasury.view', 'treasury.receive', 'treasury.pay',
                    'treasury.transfer', 'treasury.close_day',
                    'cheques.view', 'cheques.manage',
                    'installments.view', 'installments.collect', 'installments.settle_early',
                    'crm.view', 'crm.view_balance',
                    'sales.view', 'sales.view_profit',
                    'inventory.view', 'inventory.view_cost',
                    'reporting.view', 'reporting.view_financial', 'reporting.export',
                    'billing.view',
                ],
            ],

            'Warehousekeeper' => [
                'name_fa' => 'انباردار',
                'permissions' => [
                    'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.view_cost',
                    'catalog.view', 'catalog.create', 'catalog.update',
                    'purchasing.view', 'purchasing.create', 'purchasing.return',
                    'reporting.view',
                ],
            ],
        ];
    }

    /**
     * Resolve a role's permission names, expanding the `*` wildcard.
     *
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        $definition = self::roles()[$role] ?? null;

        if ($definition === null) {
            return [];
        }

        return $definition['permissions'] === ['*']
            ? self::names()
            : array_values($definition['permissions']);
    }
}
