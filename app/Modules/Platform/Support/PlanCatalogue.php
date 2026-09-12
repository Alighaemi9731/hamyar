<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Support\Money;

/**
 * The plan and module catalogue — the seed of a fresh install.
 *
 * ## What changed at DECISION GATE 6 (2026-08-29)
 *
 * A plan used to be a **bundle of modules**, and this file said which. It no longer does.
 * Every module is open to every shop; what a plan sells is **how much work a shop may
 * record in a Jalali month** (ADR 0018). So `modules()` describes what exists and whether
 * we have it switched on platform-wide, and `plans()` carries the credit matrix.
 *
 * Add-ons are gone as a product. `is_addonable`, `addon_price`, `plan_module` and
 * `subscription_addons` were dropped in 0.16.0, one release after the last code that read
 * them, per the blue/green rule in VERSIONING.
 *
 * ## These numbers seed; they do not govern
 *
 * `PlanCatalogueSeeder` writes them once, on create, and never overwrites. After that the
 * Filament panel owns them, so changing what a shop may do is a panel edit rather than a
 * deploy. Everything is integer RIAL (golden rule 2) via `Money::fromToman()`, because
 * Iranian shops quote in toman and a price typed in the wrong unit is a silent
 * factor-of-ten billing error.
 */
final class PlanCatalogue
{
    /**
     * Modules, in nav order.
     *
     * `code` matches `app/Modules/<Name>` lowercased and is the same string used by the
     * `module:` route middleware and the `features` Inertia prop — which since Gate 6 mean
     * "is this module switched on for everybody", not "did this shop buy it".
     *
     * `is_core` survives as documentation of which modules a shop cannot function without;
     * nothing gates on it any more.
     *
     * @return list<array{code: string, name_fa: string, is_core: bool}>
     */
    public static function modules(): array
    {
        return [
            ['code' => 'catalog', 'name_fa' => 'کالا', 'is_core' => true],
            ['code' => 'inventory', 'name_fa' => 'انبار', 'is_core' => true],
            ['code' => 'sales', 'name_fa' => 'فروش و صندوق', 'is_core' => true],
            ['code' => 'crm', 'name_fa' => 'مشتریان', 'is_core' => true],
            ['code' => 'purchasing', 'name_fa' => 'خرید', 'is_core' => true],
            ['code' => 'settings', 'name_fa' => 'تنظیمات', 'is_core' => true],
            ['code' => 'files', 'name_fa' => 'فایل‌ها', 'is_core' => true],
            ['code' => 'reporting', 'name_fa' => 'گزارش‌ها', 'is_core' => true],
            ['code' => 'repairs', 'name_fa' => 'تعمیرات', 'is_core' => false],
            ['code' => 'installments', 'name_fa' => 'اقساط', 'is_core' => false],
            ['code' => 'cheques', 'name_fa' => 'چک‌ها', 'is_core' => false],
            ['code' => 'treasury', 'name_fa' => 'خزانه‌داری', 'is_core' => false],
            ['code' => 'messaging', 'name_fa' => 'پیامک', 'is_core' => false],
            ['code' => 'storefront', 'name_fa' => 'ویترین', 'is_core' => false],
            ['code' => 'moadian', 'name_fa' => 'سامانه مودیان', 'is_core' => false],
            ['code' => 'hamta', 'name_fa' => 'همتا', 'is_core' => false],
        ];
    }

    /**
     * The free rung plus three paid ones, and the credits that separate them.
     *
     * Every value is per Jalali month unless the metric's window says otherwise
     * (`identity.users`, `inventory.branches`, `files.storage_mb`,
     * `storefront.price_list_links` and the two Treasury templates are standing
     * capacities). `null` means unlimited.
     *
     * **The free rung is sized deliberately.** Big enough that a one-person shop can run a
     * month on it, small enough that a real shop feels the ceiling — roughly ten invoices
     * a working day. It sits below the 500 the business plan gave *paid* Basic, because
     * that number was priced at ۲۹۰ هزار تومان and this one is priced at nothing.
     * `messaging.sms` is **0** there: SMS is the one credit that costs us cash per unit, so
     * a free rung that handed it out would be a free SMS service for anyone willing to
     * re-register. Free shops send by funding the wallet, which is money.
     *
     * ## Four rows, and why the ladder is shaped this way (2026-09-12)
     *
     * The owner's count is **three paid plans, with the free rung standing outside the
     * count** — «باید رایگان که جدا باشه، پلن‌های پولی ۳ تا باشن». ADR 0018 shipped
     * `basic` (free) · `pro` · `enterprise`, which is three *rows* and two *plans*, so a
     * rung was missing rather than a name being wrong. `business` is that rung, and it
     * goes **between free and حرفه‌ای**, not between حرفه‌ای and نامحدود, because the hole
     * in the ladder is at the bottom:
     *
     * | rung | who it is for | the wall it lifts |
     * |---|---|---|
     * | پایه · free | a shop trying the product, or a very quiet one | — |
     * | کسب‌وکار · ۲۹۰٬۰۰۰ | one counter, one or two staff, a few repairs a day | the free ceiling |
     * | حرفه‌ای · ۵۹۰٬۰۰۰ | a shop that sells and repairs all day, 2–3 branches | the counter |
     * | نامحدود · ۱٬۱۹۰٬۰۰۰ | multi-branch, high volume | every cap but two |
     *
     * **Price.** ۰ → ۲۹۰٬۰۰۰ → ۵۹۰٬۰۰۰ → ۱٬۱۹۰٬۰۰۰ تومان: each paid rung is a little over
     * double the one below it, so the ladder reads as one decision repeated rather than
     * three unrelated numbers. ۲۹۰٬۰۰۰ is **not** an invented midpoint — it is the number
     * the pre-Gate-6 business plan already put on paid Basic (500 invoices a month; see
     * ADR 0018, «Where the free column comes from»). Gate 6 moved that rung's *quota* to
     * free and never disputed its *price*, so the price was sitting unused in the
     * product's own history; re-using it beats inventing a second number for the same
     * shop. A midpoint between ۵۹۰ and ۱٬۱۹۰ — the other place a third rung could have
     * gone — would have sold a smaller version of «نامحدود» to shops that are not yet
     * asking for one, and left the shop that actually hits a wall every month with
     * nowhere to go but a ۵۹۰٬۰۰۰ plan sized four times larger than its work.
     *
     * **Quotas.** Roughly 4× the free rung and roughly a quarter of حرفه‌ای, anchored on
     * one number: **1,200 فاکتور a month ≈ 40 a working day**, which is about what one
     * person behind one counter can actually ring up. A shop that needs more needs a
     * second counter, and a second counter is حرفه‌ای's story — so the ceiling and the
     * upgrade reason are the same fact, which is what keeps a ladder honest. Units,
     * parties, products and attachments move with it, because a shop that sells four
     * times as much registers four times as many handsets and customers.
     *
     * Two deliberate exceptions to the ×4:
     *
     * - **`messaging.sms` = 1,000, a fifth of حرفه‌ای's 5,000 for half the price.** It is
     *   the one credit that costs us cash per segment, so it is the one credit that must
     *   not scale with the price. The wallet stays the way any shop sends more, on every
     *   rung, exactly as it does on the free one.
     * - **The standing capacities follow the shape of the shop, not the multiplier**:
     *   ۲ → ۴ seats and ۱ → ۲ branches, because the first thing an outgrowing one-counter
     *   shop buys is a second person and occasionally a second counter — not a chain.
     *   `inventory.transfers` is 50 against حرفه‌ای's 200 for the same reason: two branches
     *   move stock between them a couple of times a week, not a couple of times a day.
     *
     * Nothing above حرفه‌ای changed, and no existing price or credit moved: the free rung
     * and the two paid rungs are exactly what Gate 6 approved. As always these numbers
     * seed a fresh install only — after the row exists, the Filament panel owns it — so
     * the rung lands on an existing database as a new plan and nothing else.
     *
     * @return list<array{code: string, name_fa: string, tagline_fa: string, price_toman: int, limits: array<string, int|null>}>
     */
    public static function plans(): array
    {
        return [
            [
                'code' => 'basic',
                'name_fa' => 'پایه',
                'tagline_fa' => 'همهٔ امکانات، با سهمیهٔ ماهانه — رایگان و بدون کارت بانکی',
                'price_toman' => 0,
                'limits' => [
                    'sales.invoices' => 300,
                    'sales.quotes' => 100,
                    'inventory.units' => 200,
                    'catalog.products' => 200,
                    'purchasing.invoices' => 50,
                    'repairs.tickets' => 100,
                    'crm.parties' => 200,
                    'crm.follow_ups' => 100,
                    'installments.plans' => 20,
                    'cheques.cheques' => 50,
                    'inventory.transfers' => 0,
                    'inventory.stock_counts' => 1,
                    'treasury.transfers' => 60,
                    'treasury.cash_transactions' => 150,
                    'treasury.recurring_templates' => 3,
                    'treasury.rental_contracts' => 1,
                    'messaging.sms' => 0,
                    'messaging.campaigns' => 0,
                    'reporting.exports' => 30,
                    'files.attachments' => 200,
                    'files.storage_mb' => 500,
                    'identity.users' => 2,
                    'inventory.branches' => 1,
                    'storefront.price_list_links' => 1,
                ],
            ],
            [
                // The first paid rung. Sized at one counter: ~40 invoices a working day,
                // four seats, two branches — the shop that outgrew free and is nowhere
                // near needing حرفه‌ای. See the ladder note above for every number.
                'code' => 'business',
                'name_fa' => 'کسب‌وکار',
                'tagline_fa' => 'برای فروشگاه یک‌شعبه‌ای که از سقف پلن رایگان عبور کرده است',
                'price_toman' => 290_000,
                'limits' => [
                    'sales.invoices' => 1_200,
                    'sales.quotes' => 400,
                    'inventory.units' => 800,
                    'catalog.products' => 600,
                    'purchasing.invoices' => 200,
                    'repairs.tickets' => 400,
                    'crm.parties' => 800,
                    'crm.follow_ups' => 300,
                    'installments.plans' => 60,
                    'cheques.cheques' => 150,
                    // Non-zero for the first time on this rung: it is the first rung that
                    // has a second branch to move stock to.
                    'inventory.transfers' => 50,
                    'inventory.stock_counts' => 2,
                    'treasury.transfers' => 200,
                    'treasury.cash_transactions' => 400,
                    'treasury.recurring_templates' => 8,
                    'treasury.rental_contracts' => 3,
                    // A fifth of حرفه‌ای's for half the price — the one credit that costs
                    // cash per segment does not scale with what we charge.
                    'messaging.sms' => 1_000,
                    'messaging.campaigns' => 2,
                    'reporting.exports' => 100,
                    'files.attachments' => 800,
                    'files.storage_mb' => 1_500,
                    'identity.users' => 4,
                    'inventory.branches' => 2,
                    'storefront.price_list_links' => 3,
                ],
            ],
            [
                'code' => 'pro',
                'name_fa' => 'حرفه‌ای',
                // «فروشگاه», never «مغازه» — `docs/brand/voice.md`'s glossary. The seeded
                // wording in ADR 0018 predates the voice guide; this is that one word.
                'tagline_fa' => 'برای فروشگاهی که هر روز می‌فروشد و تعمیر می‌کند',
                'price_toman' => 590_000,
                'limits' => [
                    'sales.invoices' => 5_000,
                    'sales.quotes' => 1_500,
                    'inventory.units' => 3_000,
                    'catalog.products' => 2_000,
                    'purchasing.invoices' => 800,
                    'repairs.tickets' => 1_500,
                    'crm.parties' => 3_000,
                    'crm.follow_ups' => 1_000,
                    'installments.plans' => 200,
                    'cheques.cheques' => 500,
                    'inventory.transfers' => 200,
                    'inventory.stock_counts' => 4,
                    'treasury.transfers' => 600,
                    'treasury.cash_transactions' => 1_200,
                    'treasury.recurring_templates' => 20,
                    'treasury.rental_contracts' => 10,
                    'messaging.sms' => 5_000,
                    'messaging.campaigns' => 8,
                    'reporting.exports' => 300,
                    'files.attachments' => 3_000,
                    'files.storage_mb' => 5_000,
                    'identity.users' => 6,
                    'inventory.branches' => 3,
                    'storefront.price_list_links' => 5,
                ],
            ],
            [
                'code' => 'enterprise',
                'name_fa' => 'نامحدود',
                'tagline_fa' => 'بدون سقف؛ برای چند شعبه و حجم بالا',
                'price_toman' => 1_190_000,
                'limits' => [
                    // Unlimited everywhere the cost is ours only in aggregate...
                    'sales.invoices' => null,
                    'sales.quotes' => null,
                    'inventory.units' => null,
                    'catalog.products' => null,
                    'purchasing.invoices' => null,
                    'repairs.tickets' => null,
                    'crm.parties' => null,
                    'crm.follow_ups' => null,
                    'installments.plans' => null,
                    'cheques.cheques' => null,
                    'inventory.transfers' => null,
                    'inventory.stock_counts' => null,
                    'treasury.transfers' => null,
                    'treasury.cash_transactions' => null,
                    'treasury.recurring_templates' => null,
                    'treasury.rental_contracts' => null,
                    'messaging.sms' => null,
                    'messaging.campaigns' => null,
                    'reporting.exports' => null,
                    'files.attachments' => null,
                    'inventory.branches' => null,
                    'storefront.price_list_links' => null,

                    // ...and finite on the two that cost us per unit whatever we charge.
                    // Not a sales lever — an operational ceiling, lifted for a particular
                    // shop with a `tenant_limit_overrides` row that says why (Gate 6,
                    // item 9).
                    'identity.users' => 25,
                    'files.storage_mb' => 50_000,
                ],
            ],
        ];
    }

    public static function rial(int $toman): int
    {
        return Money::fromToman($toman);
    }
}
