<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Identity\Models\User;

/**
 * Everything reachable from «تنظیمات», and who may open each one.
 *
 * ## Why this exists at all
 *
 * The sidebar has linked to `/settings` since it was written and that route did not
 * exist: the Settings module's `routes/web.php` held nothing but a comment block. Every
 * user, on every page, had a nav item that returned 404.
 *
 * The screens it should have led to were all built — users, two-factor, sessions, the
 * audit log, branches, billing — just scattered across four modules with no single door.
 * A shop found them by being sent a link.
 *
 * ## Grouped the way a shopkeeper files them, not by module
 *
 * «فروشگاه» holds the things about the business; «حساب من» the things about the person
 * signed in; «امنیت و سوابق» the things you look at when something has gone wrong.
 * Grouping by owning module would file two-factor under Identity next to user management,
 * which is a distinction only a developer makes — one is "who else works here", the other
 * is "how I log in".
 *
 * ## Only destinations that exist
 *
 * Same rule as {@see \App\Modules\Reporting\Services\ReportCatalogue}: no «به‌زودی» rows.
 * A hub that lists a thing you cannot open teaches people to stop reading the hub.
 *
 * ## Permissions are checked by name, not by policy
 *
 * `$user->can('users.view')` rather than `Gate::allows('viewAny', User::class)`, because
 * this module has no business importing Inventory's `Branch` or Platform's `Subscription`
 * to ask a question about a permission string (ADR 0003). The names are the same ones the
 * owning policies check, and a feature test walks every row against the real route to
 * prove they have not drifted apart.
 */
final class SettingsCatalogue
{
    public const GROUP_SHOP = 'shop';

    public const GROUP_ACCOUNT = 'account';

    public const GROUP_SECURITY = 'security';

    /**
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return [
            self::GROUP_SHOP => 'فروشگاه',
            self::GROUP_ACCOUNT => 'حساب من',
            self::GROUP_SECURITY => 'امنیت و سوابق',
        ];
    }

    /**
     * Every destination, in the order it should be read.
     *
     * `permission` of `null` means "anyone signed in": your own sessions and your own
     * two-factor setup are not somebody else's to grant.
     *
     * `hue` names the destination on the card's icon tile, the same twelve-name palette
     * the sidebar uses (`NavHue` in `resources/js/lib/navigation.ts`). The rule that
     * matters is the sidebar's: **no two rows in the same group share one**, because a
     * group is where the eye actually compares. A hue is never a state and never means
     * "this one is special".
     *
     * @return list<array{key: string, group: string, title: string, description: string, href: string, permission: string|null, hue: string}>
     */
    public static function destinations(): array
    {
        return [
            [
                'key' => 'users',
                'group' => self::GROUP_SHOP,
                'title' => 'کاربران و نقش‌ها',
                'description' => 'دعوت همکار، تغییر نقش و غیرفعال کردن دسترسی.',
                'href' => '/settings/users',
                'permission' => 'users.view',
                'hue' => 'violet',
            ],
            [
                'key' => 'branches',
                'group' => self::GROUP_SHOP,
                'title' => 'شعبه‌ها',
                'description' => 'شعبه‌های فروشگاه و اینکه هر کاربر به کدام دسترسی دارد.',
                'href' => '/branches',
                /*
                | `settings.view`, not null. `BranchController::index()` has refused
                | anybody without it since the screen was written, so a cashier was shown
                | a card that answered 403 — the "hub that lists a thing you cannot open"
                | this class's own docblock rules out. The existing route walk runs as the
                | Owner, so it never saw it.
                */
                'permission' => 'settings.view',
                'hue' => 'emerald',
            ],
            [
                'key' => 'print',
                'group' => self::GROUP_SHOP,
                'title' => 'چاپ و هویت فروشگاه',
                'description' => 'لوگو، متن پایین فاکتور و کد QR روی برگه‌ای که به دست مشتری می‌رسد.',
                'href' => '/settings/print',
                'permission' => 'settings.view',
                'hue' => 'amber',
            ],
            [
                'key' => 'messaging',
                'group' => self::GROUP_SHOP,
                'title' => 'پیامک',
                'description' => 'اینکه کدام پیامک خودکار ارسال شود و در چه ساعت‌هایی ارسال نشود.',
                'href' => '/settings/messaging',
                'permission' => 'settings.view',
                'hue' => 'cyan',
            ],
            [
                'key' => 'billing',
                'group' => self::GROUP_SHOP,
                'title' => 'اشتراک و صورتحساب',
                'description' => 'پلن فعلی، مصرف این ماه و پرداخت‌های گذشته.',
                'href' => '/billing',
                'permission' => 'billing.view',
                'hue' => 'indigo',
            ],
            [
                'key' => 'two-factor',
                'group' => self::GROUP_ACCOUNT,
                'title' => 'ورود دومرحله‌ای',
                'description' => 'یک لایهٔ امنیتی روی حساب خودتان، با کد یک‌بارمصرف.',
                'href' => '/settings/two-factor',
                'permission' => null,
                'hue' => 'teal',
            ],
            [
                'key' => 'sessions',
                'group' => self::GROUP_ACCOUNT,
                'title' => 'دستگاه‌های واردشده',
                'description' => 'هر جایی که با حساب شما وارد شده‌اند — و خارج کردنشان.',
                'href' => '/settings/sessions',
                'permission' => null,
                'hue' => 'blue',
            ],
            [
                'key' => 'activity',
                'group' => self::GROUP_SECURITY,
                'title' => 'سوابق فعالیت',
                'description' => 'چه کسی چه چیزی را کِی تغییر داد.',
                'href' => '/settings/activity',
                'permission' => 'activity.view',
                'hue' => 'slate',
            ],
        ];
    }

    /**
     * The destinations this user may actually open, grouped and with empty groups dropped.
     *
     * An empty group would leave a heading with nothing under it, which reads as a screen
     * that failed to load rather than as a permission they do not have.
     *
     * @return list<array{key: string, label: string, items: list<array{key: string, title: string, description: string, href: string, hue: string}>}>
     */
    public static function visibleTo(?User $user): array
    {
        $groups = [];

        foreach (self::destinations() as $destination) {
            if ($destination['permission'] !== null && ($user === null || ! $user->can($destination['permission']))) {
                continue;
            }

            $groups[$destination['group']][] = [
                'key' => $destination['key'],
                'title' => $destination['title'],
                'description' => $destination['description'],
                'href' => $destination['href'],
                'hue' => $destination['hue'],
            ];
        }

        $out = [];

        foreach (self::groups() as $key => $label) {
            if (isset($groups[$key])) {
                $out[] = ['key' => $key, 'label' => $label, 'items' => $groups[$key]];
            }
        }

        return $out;
    }
}
