<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * The first morning's checklist: what a new shop has and has not done yet.
 *
 * ## Why the dashboard, and why only until it is done
 *
 * A shop that has just signed up lands on a dashboard of zeros — «۰ فاکتور»، «۰ دستگاه» —
 * which is correct and says nothing about what to do next. Six steps, in the order the
 * shop's own work happens (a product before a purchase, a purchase before a sale), each
 * with the door that completes it. The card leaves on its own once every step is done,
 * or when the owner says «بعداً».
 *
 * ## Facts, not flags
 *
 * Each step is read from the tables — a product exists, a final invoice exists — rather
 * than from a flag the step would have to set. The checklist cannot drift from what the
 * shop actually did, and there is nothing to migrate when a step changes. Six `EXISTS`
 * queries on a first-morning dashboard is a price the dashboard's own widgets already
 * pay many times over.
 *
 * ## Who sees it
 *
 * Whoever may change the shop's settings. The checklist is about setting the shop up,
 * and «همکاران را دعوت کنید» is not a salesperson's job. The dismissal is a tenant
 * setting, so it holds for everybody who could see the card.
 */
final class ShopSetupProgress
{
    /** The permission that makes the checklist somebody's business. */
    public const PERMISSION = 'settings.update';

    public const SETTING = 'setup.dismissed_at';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * The card's payload, or null when there is no card: the viewer may not set the
     * shop up, the owner dismissed it, or every step is done.
     *
     * @return array{steps: list<array{key: string, label: string, description: string, href: string, done: bool}>, done: int, total: int}|null
     */
    public function payload(?User $user): ?array
    {
        if (! $user instanceof User || ! $user->can(self::PERMISSION) || $this->dismissed()) {
            return null;
        }

        $steps = $this->steps();
        $done = count(array_filter($steps, fn (array $step): bool => $step['done']));

        if ($done === count($steps)) {
            return null;
        }

        return ['steps' => $steps, 'done' => $done, 'total' => count($steps)];
    }

    public function dismissed(): bool
    {
        return $this->context->tenant()?->setting(self::SETTING) !== null;
    }

    /**
     * @return list<array{key: string, label: string, description: string, href: string, done: bool}>
     */
    public function steps(): array
    {
        return [
            $this->step(
                'product',
                'اولین کالا را ثبت کنید',
                'یک مدل گوشی یا یک لوازم جانبی؛ رنگ و حافظه را بعداً به‌صورت ماتریس می‌سازید.',
                '/catalog/products/create',
                Product::query()->exists(),
            ),
            $this->step(
                'party',
                'اولین طرف حساب',
                'مشتری یا تأمین‌کننده — هر دو در یک فهرست‌اند.',
                '/crm/parties/create',
                Party::query()->exists(),
            ),
            $this->step(
                'purchase',
                'اولین فاکتور خرید',
                'دستگاه‌ها با IMEI از همین‌جا وارد انبار می‌شوند و شناسنامه‌شان از همین‌جا شروع می‌شود.',
                '/purchasing',
                PurchaseInvoice::query()->exists(),
            ),
            $this->step(
                'sale',
                'اولین فروش',
                'از صندوق: اسکن، پرداخت، چاپ فاکتور.',
                '/sales/pos',
                SalesInvoice::query()->where('status', InvoiceStatus::Final->value)->exists(),
            ),
            $this->step(
                'repair',
                'اولین پذیرش تعمیر',
                'قبض پذیرش چاپ می‌شود و دستگاه روی تختهٔ تعمیر می‌نشیند.',
                '/repairs/intake',
                RepairTicket::query()->exists(),
            ),
            $this->step(
                'staff',
                'همکاران را دعوت کنید',
                'هر کس با حساب خودش وارد می‌شود و هر کاری به نام خودش ثبت می‌شود.',
                '/settings/users',
                User::query()->count() > 1,
            ),
        ];
    }

    /**
     * @return array{key: string, label: string, description: string, href: string, done: bool}
     */
    private function step(string $key, string $label, string $description, string $href, bool $done): array
    {
        return compact('key', 'label', 'description', 'href', 'done');
    }
}
