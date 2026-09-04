<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Services\ChequeTransitions;
use App\Modules\Cheques\Services\RegisterCheque;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\Identity\Models\User;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Installments\Services\CollectInstallment;
use App\Modules\Installments\Services\CreateInstallmentPlan;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\UnitStateMachine;
use App\Modules\Messaging\Services\SendSms;
use App\Modules\Messaging\Services\SmsWallet;
use App\Modules\Platform\Events\SubscriptionActivated;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use App\Modules\Purchasing\Models\PurchaseUnitItem;
use App\Modules\Purchasing\Services\ReceivePurchaseInvoice;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Services\DeliverTicket;
use App\Modules\Repairs\Services\QuoteApproval;
use App\Modules\Repairs\Services\TicketIntake;
use App\Modules\Repairs\Services\TicketParts;
use App\Modules\Repairs\Services\TicketStateMachine;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\FinaliseInvoice;
use App\Modules\Sales\Services\InvoiceTotals;
use App\Modules\Treasury\Enums\CashDirection;
use App\Modules\Treasury\Models\TransactionCategory;
use App\Modules\Treasury\Services\RecordCashTransaction;
use App\Modules\Treasury\Services\TransferBetweenAccounts;
use App\Support\Imei;
use App\Support\Money;
use App\Support\Quota\QuotaGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A shop worth photographing — `php artisan db:seed --class=ShowcaseSeeder`.
 *
 * {@see DemoShopSeeder} gives the demo tenant one sale, ten handsets and four parties. That
 * is enough to click through and nowhere near enough to *look* at: the dashboard's 30-day
 * chart is a flat line at zero, the repairs board has six empty columns, the collections
 * desk («میز وصول») says there is nothing to collect, and the profit report has one row.
 * Every screenshot on the landing page would be a picture of an empty product.
 *
 * This seeder fills that shop in. It is **deliberately not** part of `DatabaseSeeder`:
 * `make fresh` stays fast, and this is called on demand by whoever is capturing screens.
 *
 * ## Everything is driven through the real services
 *
 * Same discipline as {@see DemoShopSeeder} and {@see CrazyMonthSeeder}, for the same
 * reason. Purchases go through `ReceivePurchaseInvoice`, sales through `InvoiceTotals` and
 * `FinaliseInvoice`, tickets through `TicketIntake` and `TicketStateMachine`, cheques
 * through `RegisterCheque`, plans through `CreateInstallmentPlan`. A hand-inserted fixture
 * looks right on a list screen and falls apart on the first screen that reconciles
 * anything — and a screenshot of a screen whose numbers disagree with each other is worse
 * than no screenshot, because it ships.
 *
 * ## The clock is moved, rather than the rows edited afterwards
 *
 * The dashboard chart, the sales report and the profit report all read
 * `sales_invoices.issued_at`, which `FinaliseInvoice` stamps as `now()`. Backdating that
 * column afterwards would leave the ledger entries, the stock movements and the IMEI
 * passport lines all stamped today — three registers disagreeing about when the same sale
 * happened, which is precisely the class of damage the ledger rules exist to prevent.
 *
 * So the clock is moved instead ({@see clockAt()}), each service writes the timestamp it
 * would really have written, and the clock is restored in a `finally`. Every quota spend
 * lands in the Jalali month it belongs to as a side effect, which is the honest answer.
 *
 * ## Deterministic, so two runs produce the same screenshots
 *
 * No Faker, no `random_int`. Amounts, customers, models, faults, day-by-day invoice counts
 * and dates are all fixed tables indexed by ordinal, and the only randomness — the IMEI
 * bodies — comes from `mt_rand()` after a fixed `mt_srand(SEED)`. Re-running against a
 * fresh database gives byte-identical figures on every screen a camera can see. The one
 * exception is opaque: `Str::random()` tracking and approval tokens use the CSPRNG and
 * cannot be seeded, and nothing renders them as content.
 *
 * ## The demo shop is moved to «حرفه‌ای» (pro), and that is not a workaround
 *
 * The free rung sells 0 SMS and 2 seats. This shop needs a message log to photograph and
 * five people to attribute work to, so it is *sold a bigger plan* rather than having the
 * guard stepped around — `QuotaGuard::consume()` is called on every metered path exactly as
 * a shopkeeper's button press would call it, and the usage screen therefore shows real
 * numbers. Everything else fits inside «پایه» comfortably; SMS and seats are the only two
 * that do not.
 *
 * ## Idempotent-ish
 *
 * A repair ticket is the sentinel: if the shop has one, this has already run and returns
 * rather than doubling the data. It is not a merge — re-seeding a shop is `make fresh`.
 */
class ShowcaseSeeder extends Seeder
{
    /** Fixed, so the IMEI bodies are the same on every run. */
    private const SEED = 14050613;

    /** The plan the demo shop ends on. See the class docblock. */
    private const PLAN = 'pro';

    /**
     * Invoices per day for the last 30 days, oldest first.
     *
     * A literal rather than a random draw: a chart is a shape, and a shape somebody has
     * chosen reads as a business. The two zeros are the Fridays the shop is shut, and the
     * peaks are the days before them.
     *
     * @var list<int>
     */
    private const INVOICES_PER_DAY = [
        2, 1, 0, 3, 2, 2, 1,
        2, 2, 0, 4, 3, 1, 1,
        1, 2, 2, 2, 3, 2, 1,
        1, 3, 2, 3, 1, 2, 2,
        2, 1,
    ];

    /**
     * The people who buy here. [name, mobile, city, street, opening balance in toman —
     * positive means they already owed the shop when the software arrived].
     *
     * @var list<array{0:string,1:string,2:string,3:string,4:int}>
     */
    private const CUSTOMERS = [
        ['سمیرا احمدی‌فر', '09351230001', 'تهران', 'خیابان جمهوری، پاساژ علاءالدین، طبقهٔ ۲', 0],
        ['حسین موسوی‌نژاد', '09190000102', 'تهران', 'نارمک، خیابان آیت، کوچهٔ ۱۲', 4_600_000],
        ['فاطمه رستمی', '09123330003', 'کرج', 'گوهردشت، فاز ۲، بلوار انقلاب', 0],
        ['علی رضایی', '09127770004', 'تهران', 'پیروزی، خیابان نبرد شمالی', 12_400_000],
        ['مریم کاظمی', '09362220005', 'تهران', 'سعادت‌آباد، بلوار دریا', 0],
        ['محمد حسینی', '09121110006', 'تهران', 'یافت‌آباد، بازار مبل', 0],
        ['زهرا محمدی', '09359990007', 'شهریار', 'خیابان ولیعصر، کوچهٔ گلستان', 2_150_000],
        ['امیرحسین نوری', '09128880008', 'تهران', 'تهرانپارس، فلکهٔ سوم', 0],
        ['نرگس صادقی', '09195550009', 'تهران', 'ولنجک، خیابان دانشجو', 0],
        ['رضا کریمی', '09124440010', 'ری', 'شهرری، خیابان فداییان اسلام', -1_800_000],
        ['سارا جعفری', '09366660011', 'تهران', 'پونک، بلوار عدل', 0],
        ['مهدی عباسی', '09122220012', 'تهران', 'جنت‌آباد جنوبی، خیابان لاله', 0],
        ['الهام شریفی', '09193330013', 'کرج', 'مهرشهر، بلوار ارم', 0],
        ['کامران یوسفی', '09121230014', 'تهران', 'شهرک غرب، خیابان ایران‌زمین', 7_300_000],
        ['لیلا فرهادی', '09355550015', 'تهران', 'نازی‌آباد، خیابان مدائن', 0],
    ];

    /**
     * The handset models on the shelf. [name, brand, sku, retail toman, «همکار» toman].
     *
     * Retail is what a walk-in pays; the trade price is what the two colleague shops pay.
     * Every unit's landed cost is set below the trade price in {@see receiveShipment()},
     * so the profit report shows a margin on every model rather than a loss on one.
     *
     * @var list<array{0:string,1:string,2:string,3:int,4:int}>
     */
    private const HANDSETS = [
        ['آیفون ۱۶ پرو ۲۵۶ گیگ', 'Apple', 'IP16P-256', 118_000_000, 114_500_000],
        ['آیفون ۱۵ ۱۲۸ گیگ', 'Apple', 'IP15-128', 74_000_000, 71_800_000],
        ['آیفون ۱۳ ۱۲۸ گیگ', 'Apple', 'IP13-128', 49_500_000, 47_900_000],
        ['گلکسی S24 اولترا ۲۵۶ گیگ', 'Samsung', 'S24U-256', 96_000_000, 93_200_000],
        ['گلکسی A55 ۲۵۶ گیگ', 'Samsung', 'A55-256', 31_500_000, 30_400_000],
        ['گلکسی A35 ۱۲۸ گیگ', 'Samsung', 'A35-128', 22_800_000, 22_000_000],
        ['ردمی نوت ۱۳ پرو ۲۵۶ گیگ', 'Xiaomi', 'RN13P-256', 24_900_000, 24_000_000],
        ['پوکو X6 پرو ۲۵۶ گیگ', 'Xiaomi', 'PX6P-256', 27_400_000, 26_500_000],
    ];

    /**
     * Accessories that appear on sales lines. [name, sku, barcode, reorder threshold, retail].
     *
     * @var list<array{0:string,1:string,2:string,3:int,4:int}>
     */
    private const ACCESSORIES = [
        ['گلس تمام‌صفحه', 'ACC-GLS-FULL', '6260000000033', 10, 350_000],
        ['قاب سیلیکونی', 'ACC-CASE-SIL', '6260000000040', 10, 280_000],
        ['کابل تایپ‌سی ۱ متری', 'ACC-CBL-C1M', '6260000000057', 8, 420_000],
        ['هندزفری بلوتوث', 'ACC-BT-EAR', '6260000000064', 5, 2_450_000],
        ['پاوربانک ۲۰ هزار', 'ACC-PB-20K', '6260000000071', 4, 1_980_000],
        ['شارژر دیواری ۳۳ وات', 'ACC-CHG-33W', '6260000000088', 6, 890_000],
    ];

    /**
     * Two lines stocked thin against a high threshold and never sold, so the low-stock
     * card is true on every run. [name, sku, barcode, threshold, retail].
     *
     * @var list<array{0:string,1:string,2:string,3:int,4:int}>
     */
    private const THIN_LINES = [
        ['محافظ لنز دوربین', 'ACC-LENS', '6260000000095', 12, 150_000],
        ['شارژر فندکی خودرو', 'ACC-CAR-CHG', '6260000000101', 12, 520_000],
    ];

    /**
     * Spare parts the bench fits. [name, sku, barcode, threshold, retail].
     *
     * @var list<array{0:string,1:string,2:string,3:int,4:int}>
     */
    private const PARTS = [
        ['باتری آیفون ۱۳', 'PRT-BAT-IP13', '6260000000118', 3, 3_200_000],
        ['گلس و تاچ گلکسی A55', 'PRT-LCD-A55', '6260000000125', 2, 5_800_000],
        ['سوکت شارژ تایپ‌سی', 'PRT-SOCKET-C', '6260000000132', 5, 480_000],
        ['دوربین پشت ردمی نوت ۱۳', 'PRT-CAM-RN13', '6260000000149', 2, 2_650_000],
    ];

    /**
     * Standard-line quantities per delivery, keyed by sku, with the unit cost in toman.
     *
     * @var array<string, array{0:int,1:int}>
     */
    private const FIRST_DELIVERY = [
        'ACC-GLS-FULL' => [60, 180_000],
        'ACC-CASE-SIL' => [45, 140_000],
        'ACC-CBL-C1M' => [40, 230_000],
        'ACC-BT-EAR' => [30, 1_650_000],
        'ACC-PB-20K' => [24, 1_350_000],
        'ACC-CHG-33W' => [32, 560_000],
        'ACC-LENS' => [3, 70_000],
        'ACC-CAR-CHG' => [2, 300_000],
        'PRT-BAT-IP13' => [6, 2_100_000],
        'PRT-LCD-A55' => [4, 4_100_000],
        'PRT-SOCKET-C' => [10, 260_000],
        'PRT-CAM-RN13' => [3, 1_800_000],
    ];

    /** @var array<string, array{0:int,1:int}> */
    private const SECOND_DELIVERY = [
        'ACC-GLS-FULL' => [20, 185_000],
        'ACC-CBL-C1M' => [12, 235_000],
        'ACC-BT-EAR' => [10, 1_680_000],
        'ACC-PB-20K' => [8, 1_360_000],
    ];

    /** Real "now", captured before the clock is moved anywhere. */
    private CarbonImmutable $origin;

    private int $owner = 0;

    /** @var list<int> ids of the two people who serve the counter */
    private array $salespeople = [];

    /** @var list<int> ids of the two people on the bench */
    private array $technicians = [];

    /** @var list<int> customer party ids, in a fixed order */
    private array $customers = [];

    /** @var list<int> handset unit ids still on the shelf, oldest shipment first */
    private array $sellableUnits = [];

    /** @var list<int> variant ids of the accessories that appear on sales lines */
    private array $accessoryVariants = [];

    /** @var list<int> ids of finalised invoices left with a balance owing */
    private array $creditInvoices = [];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo')->first();

        if (! $tenant instanceof Tenant) {
            return;
        }

        $this->origin = CarbonImmutable::now();

        mt_srand(self::SEED);

        $context = app(TenantContext::class);

        $seeded = $context->runFor($tenant, fn (): bool => RepairTicket::query()->exists());

        if ($seeded === true) {
            $context->forget();
            $this->command?->warn('  ShowcaseSeeder: the demo shop already has repair tickets — nothing to do.');

            return;
        }

        $this->sellPlan($tenant);

        try {
            $context->runFor($tenant, function (): void {
                $owner = User::query()->orderBy('id')->firstOrFail();

                // Seeding acts as the owner, so every history line, every ledger batch and
                // every ticket move names a person. "unknown" on every row of a demo is a
                // poor advertisement for an audit trail.
                auth()->setUser($owner);
                $this->owner = $owner->id;

                $this->seedTeam();
                $this->seedAccounts();
                $this->seedParties();
                $this->seedCatalogue();
                $this->seedShipments();
                $this->seedOlderCreditSales();
                $this->seedInstallmentPlans();
                $this->seedMonthOfSales();
                $this->seedUnitStates();
                $this->seedRepairs();
                $this->seedCheques();
                $this->seedTreasury();
                $this->seedMessages();
            });
        } finally {
            // Always, and before anything else runs in this process: a seeder that dies
            // with the clock parked in the past leaves every later command lying about
            // the date.
            CarbonImmutable::setTestNow(null);

            $context->forget();
        }

        $this->report();
    }

    /* ------------------------------------------------------------ the plan -- */

    /**
     * Move the demo shop onto «حرفه‌ای».
     *
     * A platform act, so it runs through `runAsPlatform()` — `subscriptions` is RLS
     * protected and selling a plan is not something a tenant does to itself (ADR 0002
     * amendment). The newest subscription row wins in `SubscriptionResolver`, and
     * `SubscriptionActivated` is what bumps `tenants.entitlement_version` so the cached
     * limits in this process and any other are stale from this moment.
     */
    private function sellPlan(Tenant $tenant): void
    {
        $plan = Plan::query()->where('code', self::PLAN)->first();

        if (! $plan instanceof Plan) {
            return;
        }

        $subscription = app(TenantContext::class)->runAsPlatform(
            fn (): Subscription => Subscription::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                // Wide enough that the period still contains the seeder's oldest travelled
                // date — a subscription that had not started yet is not usable, and the
                // shop would silently fall back to the free rung mid-run.
                'current_period_start' => $this->origin->subMonths(6),
                'current_period_end' => $this->origin->addMonths(6),
                'plan_changed_at' => $this->origin,
            ])
        );

        SubscriptionActivated::dispatch($subscription);
    }

    /* ------------------------------------------------------------- the shop -- */

    /**
     * Two on the counter and two on the bench, so work has somebody's name against it.
     *
     * `identity.users` is a standing capacity measured by counting active users rather
     * than a credit that is spent, so there is nothing to `consume()` here — five people
     * against the six «حرفه‌ای» seats is simply what the measure returns.
     */
    private function seedTeam(): void
    {
        foreach ([
            ['نگین شفیعی', '09121110001', 'Salesperson'],
            ['آرش دهقانی', '09121110002', 'Salesperson'],
            ['میلاد اکبری', '09121110003', 'Technician'],
            ['پویا رستمی', '09121110004', 'Technician'],
        ] as [$name, $mobile, $role]) {
            /** @var User $user */
            $user = User::query()->create([
                'name' => $name,
                'mobile' => $mobile,
                'password' => 'password',
                'is_active' => true,
            ]);

            $user->assignRole($role);

            if ($role === 'Salesperson') {
                $this->salespeople[] = $user->id;
            } else {
                $this->technicians[] = $user->id;
            }
        }
    }

    /**
     * A bank, a card terminal and the expense headings a month of trading needs.
     *
     * Matched by TYPE rather than by name, for the reason `CrazyMonthSeeder` spells out:
     * `DemoShopSeeder` has already made a till, and a second «صندوق» would split the
     * month's takings across two drawers that each look half empty.
     */
    private function seedAccounts(): void
    {
        $branchId = Warehouse::query()->where('is_sellable', true)->firstOrFail()->branch_id;

        $till = $this->accountOfType(Account::TYPE_CASH, 'صندوق فروشگاه', [
            'is_default' => true,
            'branch_id' => $branchId,
        ]);

        // A float in the drawer, so banking the takings does not overdraw it.
        $till->forceFill(['opening_balance' => Money::fromToman(40_000_000)])->save();

        $this->accountOfType(Account::TYPE_BANK, 'بانک ملت — جاری', [
            'bank_name' => 'ملت',
            'iban' => 'IR820540102680020817909002',
            'opening_balance' => Money::fromToman(600_000_000),
        ]);

        $this->accountOfType(Account::TYPE_POS_TERMINAL, 'کارتخوان بانک ملت', [
            'terminal_number' => '12345678',
        ]);

        $this->accountOfType(Account::TYPE_SALES, 'درآمد فروش');
        $this->accountOfType(Account::TYPE_INVENTORY, 'ارزش موجودی انبار');
    }

    /**
     * Fifteen customers, three suppliers and two «همکار» shops.
     *
     * `crm.parties` is spent for each one, in the same transaction that writes the row —
     * the same thing `PartyController` does. The alternative is a usage screen that says
     * the shop has registered four parties while its own list shows twenty-four.
     */
    private function seedParties(): void
    {
        $this->clockAt(132, 9);

        $reseller = PriceLevel::query()->where('code', PriceLevel::RESELLER)->first();

        foreach (self::CUSTOMERS as $index => [$name, $mobile, $city, $line, $opening]) {
            $party = $this->makeParty([
                'kind' => 'customer',
                'name' => $name,
                'opening_balance' => $opening === 0 ? 0 : Money::fromToman($opening),
                'credit_limit' => Money::fromToman(($index % 4) * 20_000_000),
            ], $mobile);

            $party->addresses()->create([
                'label' => 'نشانی اصلی',
                'province' => 'تهران',
                'city' => $city,
                'line' => $line,
                'is_primary' => true,
            ]);

            $this->customers[] = $party->id;
        }

        foreach ([
            ['بازرگانی موبایل خاورمیانه', 'شرکت بازرگانی خاورمیانه رایان', '02133445511'],
            ['پخش لوازم جانبی پایتخت', 'پخش پایتخت', '02155667788'],
            ['وارداتی قطعات نگین‌تک', 'نگین‌تک پارس', '02166554433'],
        ] as [$name, $company, $phone]) {
            $this->makeParty([
                'kind' => 'supplier',
                'name' => $name,
                'company_name' => $company,
            ], $phone, PartyContact::TYPE_PHONE);
        }

        foreach ([
            ['موبایل ستاره — پاساژ علاءالدین', '09121230011', 8_400_000],
            ['گالری موبایل نگین', '09121230022', -3_150_000],
        ] as [$name, $mobile, $opening]) {
            $this->makeParty([
                'kind' => 'colleague',
                'name' => $name,
                'price_level_id' => $reseller?->getKey(),
                'opening_balance' => Money::fromToman($opening),
                'credit_limit' => Money::fromToman(150_000_000),
            ], $mobile);
        }
    }

    /**
     * The handsets, accessories and spare parts the rest of the file trades in.
     *
     * Two of the accessory lines are stocked deliberately thin against a high reorder
     * threshold — «محافظ لنز دوربین» and «شارژر فندکی خودرو» — and are never sold, so the
     * low-stock card has something true to show on every run rather than whatever the
     * month's sales happened to leave behind.
     */
    private function seedCatalogue(): void
    {
        $this->clockAt(131, 10);

        $phones = Category::query()->where('name', 'گوشی موبایل')->first()
            ?? Category::query()->create(['name' => 'گوشی موبایل', 'slug' => 'گوشی-موبایل', 'position' => 1]);

        $accessories = Category::query()->where('name', 'لوازم جانبی')->first()
            ?? Category::query()->create(['name' => 'لوازم جانبی', 'slug' => 'لوازم-جانبی', 'position' => 2]);

        $parts = Category::query()->create([
            'name' => 'قطعات تعمیری',
            'slug' => 'قطعات-تعمیری',
            'position' => 3,
        ]);

        $level = PriceLevel::query()->where('is_default', true)->firstOrFail();
        $reseller = PriceLevel::query()->where('code', PriceLevel::RESELLER)->first() ?? $level;
        $prices = app(PriceResolver::class);

        foreach (self::HANDSETS as [$name, $brandName, $sku, $retail, $trade]) {
            $brand = Brand::query()->where('name', $brandName)->first();

            $product = $this->makeProduct([
                'name' => $name,
                'type' => 'serialized',
                'brand_id' => $brand?->getKey(),
                'category_id' => $phones->id,
            ]);

            /** @var ProductVariant $variant */
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'options' => [],
                'sku' => $sku,
            ]);

            $prices->setPrice($variant->id, $level->id, Money::fromToman($retail));
            $prices->setPrice($variant->id, $reseller->id, Money::fromToman($trade));
        }

        foreach (self::ACCESSORIES as [$name, $sku, $barcode, $threshold, $retail]) {
            $variant = $this->makeStandardLine($name, $sku, $barcode, $threshold, $accessories->id);

            $prices->setPrice($variant->id, $level->id, Money::fromToman($retail));

            $this->accessoryVariants[] = $variant->id;
        }

        foreach (self::THIN_LINES as [$name, $sku, $barcode, $threshold, $retail]) {
            $variant = $this->makeStandardLine($name, $sku, $barcode, $threshold, $accessories->id);

            $prices->setPrice($variant->id, $level->id, Money::fromToman($retail));
        }

        foreach (self::PARTS as [$name, $sku, $barcode, $threshold, $retail]) {
            $variant = $this->makeStandardLine($name, $sku, $barcode, $threshold, $parts->id);

            $prices->setPrice($variant->id, $level->id, Money::fromToman($retail));
        }
    }

    /**
     * Two deliveries, received for real, three months apart — the first before the oldest
     * sale this file makes, so every handset has a price in effect when it is sold.
     *
     * The first is what the older instalment sales are drawn from; the second restocks the
     * month the screenshots are of. `ReceivePurchaseInvoice` writes the landed costs, the
     * stock movements, the supplier credit and the IMEI passports — so profit on the sales
     * below is a real margin over a real cost rather than a number this file invented.
     */
    private function seedShipments(): void
    {
        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $suppliers = Party::query()
            ->where('kind', 'supplier')
            ->orderBy('id')
            ->get()
            ->map(fn (Party $party): int => $party->id)
            ->all();

        // First delivery: every handset model, the accessories, the parts, the thin lines.
        // Before the oldest sale (120 days back), so every unit sold has a price in effect.
        $this->clockAt(128, 8);
        $this->receiveShipment(
            $warehouse,
            $suppliers[1] ?? $suppliers[0],
            'PUR-1405-0031',
            handsets: 16,
            quantities: self::FIRST_DELIVERY,
        );

        // Second delivery: handsets only, to restock the month being photographed.
        $this->clockAt(34, 8);
        $this->receiveShipment(
            $warehouse,
            $suppliers[0],
            'PUR-1405-0058',
            handsets: 14,
            quantities: self::SECOND_DELIVERY,
        );

        // The shelf this seeder sells from is the stock it received, oldest first.
        // DemoShopSeeder's leftovers stay out of it on purpose: their prices were set at
        // the real "now", so at a clock parked three months back no price is in effect and
        // a handset would ring up at zero — which is exactly what happened the first time
        // this ran, and why retailFor() now refuses a missing price instead of returning 0.
        $skus = array_column(self::HANDSETS, 2);

        $this->sellableUnits = array_values(ProductUnit::query()
            ->where('status', UnitStatus::InStock)
            ->whereHas('variant', fn ($query) => $query->whereIn('sku', $skus))
            ->orderBy('id')
            ->get()
            ->map(fn (ProductUnit $unit): int => $unit->id)
            ->all());
    }

    /* ------------------------------------------------------------- the sales -- */

    /**
     * Three credit sales from before the window, so the collections desk has history.
     *
     * They exist because an instalment schedule needs somewhere to start: rows that are
     * two and three months overdue cannot hang off a sale made a fortnight ago, and a
     * «میز وصول» whose worst debtor is nine days late does not show what the screen is for.
     *
     * @var list<array{0:int,1:int,2:int}> [days ago, customer ordinal, down payment in toman]
     */
    private const OLDER_CREDIT_SALES = [
        [92, 0, 24_000_000],
        [76, 3, 31_000_000],
        [58, 6, 18_500_000],
    ];

    private function seedOlderCreditSales(): void
    {
        foreach (self::OLDER_CREDIT_SALES as [$daysAgo, $customerIndex, $downToman]) {
            $this->clockAt($daysAgo, 10, 30);

            $invoice = $this->sellHandset(
                customerIndex: $customerIndex,
                salespersonIndex: $customerIndex % 2,
                paidToman: $downToman,
            );

            $this->creditInvoices[] = $invoice->id;
        }
    }

    /* ------------------------------------------------------------- the month -- */

    /**
     * Thirty days of trading, shaped by {@see INVOICES_PER_DAY}.
     *
     * Roughly one invoice in three carries a handset; the rest are accessories, which is
     * what a phone shop's day actually looks like. Payment is split the way an Iranian
     * counter splits it — card first, cash for the rest — and one sale in six is left with
     * a balance owing so the register shows the amber «باقی‌مانده» column doing its job.
     */
    private function seedMonthOfSales(): void
    {
        $ordinal = 0;

        foreach (self::INVOICES_PER_DAY as $offset => $count) {
            $daysAgo = count(self::INVOICES_PER_DAY) - 1 - $offset;

            foreach (range(1, $count) as $slot) {
                if ($count === 0) {
                    break;
                }

                $this->clockAt($daysAgo, 7 + $slot * 2, ($ordinal * 7) % 60);

                $customerIndex = $ordinal % count(self::CUSTOMERS);
                $leaveBalance = $ordinal % 6 === 5;

                if ($ordinal % 3 === 0 && $this->sellableUnits !== []) {
                    $invoice = $this->sellHandset(
                        customerIndex: $customerIndex,
                        salespersonIndex: $ordinal % 2,
                        paidToman: $leaveBalance ? null : 0,
                    );
                } else {
                    $invoice = $this->sellAccessories(
                        customerIndex: $customerIndex,
                        salespersonIndex: $ordinal % 2,
                        ordinal: $ordinal,
                        leaveBalance: $leaveBalance,
                    );
                }

                if ($leaveBalance) {
                    $this->creditInvoices[] = $invoice->id;
                }

                $ordinal++;
            }
        }
    }

    /**
     * Sell the next handset on the shelf to a customer, with an accessory beside it.
     *
     * `$paidToman` is the amount the customer pays today in toman; `0` means "pay in
     * full", `null` means "pay the card share and leave the rest owing". The same service
     * chain as the till: create → items → `InvoiceTotals` → payments → `FinaliseInvoice`.
     */
    private function sellHandset(int $customerIndex, int $salespersonIndex, ?int $paidToman): SalesInvoice
    {
        $unitId = array_shift($this->sellableUnits);

        if ($unitId === null) {
            throw new RuntimeException('ShowcaseSeeder: the shelf ran out of handsets before the month did — receive more in seedShipments().');
        }

        /** @var ProductUnit $unit */
        $unit = ProductUnit::query()->with('variant.product')->findOrFail($unitId);
        $retail = $this->retailFor($unit->product_variant_id);

        $invoice = $this->openInvoice($customerIndex, $salespersonIndex);

        $invoice->items()->create([
            'product_variant_id' => $unit->product_variant_id,
            'product_unit_id' => $unit->id,
            'description' => $this->nameOf($unit->product_variant_id),
            'quantity' => 1,
            'unit_price' => $retail,
            'vat_rate' => 10,
            'line_total' => 0,
            'warranty_months' => 18,
        ]);

        $accessory = $this->accessoryVariants[$customerIndex % count($this->accessoryVariants)];

        $invoice->items()->create([
            'product_variant_id' => $accessory,
            'description' => $this->nameOf($accessory),
            'quantity' => 1,
            'unit_price' => $this->retailFor($accessory),
            'vat_rate' => 10,
            'line_total' => 0,
        ]);

        return $this->settleAndFinalise($invoice, $salespersonIndex, $paidToman);
    }

    /** Two or three accessory lines, no handset. */
    private function sellAccessories(int $customerIndex, int $salespersonIndex, int $ordinal, bool $leaveBalance): SalesInvoice
    {
        $invoice = $this->openInvoice($customerIndex, $salespersonIndex);
        $lines = 2 + ($ordinal % 2);

        foreach (range(0, $lines - 1) as $line) {
            $variant = $this->accessoryVariants[($ordinal + $line) % count($this->accessoryVariants)];

            $invoice->items()->create([
                'product_variant_id' => $variant,
                'description' => $this->nameOf($variant),
                'quantity' => 1 + ($line === 0 ? $ordinal % 2 : 0),
                'unit_price' => $this->retailFor($variant),
                'vat_rate' => 10,
                'line_total' => 0,
            ]);
        }

        return $this->settleAndFinalise($invoice, $salespersonIndex, $leaveBalance ? null : 0);
    }

    private function openInvoice(int $customerIndex, int $salespersonIndex): SalesInvoice
    {
        $branch = Branch::query()->where('is_default', true)->firstOrFail();

        /** @var SalesInvoice $invoice */
        $invoice = SalesInvoice::query()->create([
            'branch_id' => $branch->id,
            'party_id' => $this->customers[$customerIndex],
            'salesperson_id' => $this->salespeople[$salespersonIndex] ?? $this->owner,
            'status' => InvoiceStatus::Draft,
            'settings_snapshot' => [
                'rounding_step' => 10_000,
                'rounding_direction' => 'nearest',
            ],
        ]);

        return $invoice;
    }

    /**
     * Recalculate, take the money, finalise.
     *
     * `$paidToman` is what the customer pays today: `0` means the whole invoice, `null`
     * means "the card share, and the cash share is left owing", and a positive figure is
     * a deposit — that amount and no more, split card-first, with the rest financed. The
     * amounts are read back from `InvoiceTotals` rather than computed here, so the split
     * is right to the rial whatever the rounding step did to the total.
     */
    private function settleAndFinalise(SalesInvoice $invoice, int $salespersonIndex, ?int $paidToman): SalesInvoice
    {
        app(InvoiceTotals::class)->recalculate($invoice->refresh());
        $invoice->refresh();

        $total = (int) $invoice->total;
        $cash = Account::query()->where('type', Account::TYPE_CASH)->orderByDesc('is_default')->firstOrFail();
        $terminal = Account::query()->where('type', Account::TYPE_POS_TERMINAL)->first() ?? $cash;

        $paying = match (true) {
            $paidToman === 0 => $total,
            $paidToman === null => intdiv((int) ($total * 0.6), 10_000) * 10_000,
            default => min($total, Money::fromToman($paidToman)),
        };

        // Roughly 60% of whatever is paid goes on the card, rounded to a whole 10,000 rial
        // so the receipt reads like one a terminal actually printed; the rest is cash.
        $card = intdiv((int) ($paying * 0.6), 10_000) * 10_000;
        $cashShare = $paying - $card;

        if ($card > 0) {
            $invoice->payments()->create([
                'method' => PaymentMethod::PosTerminal,
                'account_id' => $terminal->id,
                'amount' => $card,
                'reference' => (string) (100_000 + ($invoice->id * 7919) % 900_000),
            ]);
        }

        if ($cashShare > 0) {
            $invoice->payments()->create([
                'method' => PaymentMethod::Cash,
                'account_id' => $cash->id,
                'amount' => $cashShare,
            ]);
        }

        app(FinaliseInvoice::class)->finalise(
            $invoice->refresh(),
            $this->salespeople[$salespersonIndex] ?? $this->owner,
        );

        return $invoice->refresh();
    }

    private function retailFor(int $variantId): int
    {
        $level = PriceLevel::query()->where('is_default', true)->firstOrFail();
        $price = app(PriceResolver::class)->priceFor($variantId, $level->id);

        if ($price === null || $price <= 0) {
            throw new RuntimeException(sprintf(
                'ShowcaseSeeder: variant %d has no price in effect at %s — a handset must never ring up at zero.',
                $variantId,
                CarbonImmutable::now()->toDateString(),
            ));
        }

        return $price;
    }

    private function nameOf(int $variantId): string
    {
        return (string) ProductVariant::query()->with('product')->findOrFail($variantId)->product->name;
    }

    /* --------------------------------------------------------------- the shelf -- */

    /**
     * Give the shelf a few stories: a reservation, a bench visit, and the four handsets
     * still waiting for their HAMTA registration — the honest state the module exists to
     * chase.
     */
    private function seedUnitStates(): void
    {
        $this->clockAt(3, 11);

        $machine = app(UnitStateMachine::class);

        $onShelf = ProductUnit::query()
            ->whereIn('id', $this->sellableUnits)
            ->orderBy('id')
            ->get();

        foreach ($onShelf->take(2) as $unit) {
            $machine->transition($unit, UnitStatus::Reserved, null, 'رزرو تلفنی؛ مشتری فردا مراجعه می‌کند');
        }

        foreach ($onShelf->slice(2, 2) as $unit) {
            $machine->transition($unit, UnitStatus::InRepair, null, 'بررسی باتری پیش از فروش');
        }

        foreach ($onShelf->slice(4, 4) as $unit) {
            $unit->update(['hamta_status' => ProductUnit::HAMTA_PENDING]);
        }

        // Anything reserved or on the bench is no longer for sale this month.
        $parked = $onShelf->take(4)->map(fn (ProductUnit $unit): int => $unit->id)->all();

        $this->sellableUnits = array_values(array_diff($this->sellableUnits, $parked));
    }

    /* ------------------------------------------------------ the instalments -- */

    /**
     * Five plans, so the collections desk shows every state it has a word for.
     *
     * A plan finances whatever the invoice still owes — there is no separate down-payment
     * argument; the deposit is what was paid at the counter. Three plans hang off the
     * older credit sales and are late by weeks, which is what a «میز وصول» is for; one is
     * settled in full; one was opened ten days ago and has nothing due yet.
     */
    private function seedInstallmentPlans(): void
    {
        $creator = app(CreateInstallmentPlan::class);
        $collector = app(CollectInstallment::class);
        $till = Account::query()->where('type', Account::TYPE_CASH)->orderByDesc('is_default')->firstOrFail();

        // [invoice ordinal in creditInvoices, months, profit %, first due days ago, rows to collect]
        foreach ([
            [0, 6, 4, 62, 1],   // one paid on time, then silence — the worst debtor
            [1, 4, 3, 46, 0],   // nothing paid, two rows overdue
            [2, 3, 0, 28, 0],   // interest-free, nothing paid, first row just overdue
        ] as [$ordinal, $months, $profit, $firstDueDaysAgo, $collect]) {
            $invoiceId = $this->creditInvoices[$ordinal] ?? null;

            if ($invoiceId === null) {
                continue;
            }

            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::query()->findOrFail($invoiceId);

            // Opened the day after the sale, as a shop actually does it.
            $openedAt = $this->clockAt($firstDueDaysAgo + 30, 12);

            $plan = $creator->fromInvoice(
                invoice: $invoice,
                count: $months,
                profitPercent: $profit,
                firstDueAt: $openedAt->addDays(30)->startOfDay(),
                intervalMonths: 1,
                actorId: $this->owner,
            );

            /** @var list<InstallmentRow> $rows */
            $rows = $plan->rows()->orderBy('sequence')->get()->all();

            foreach (array_slice($rows, 0, $collect) as $row) {
                // Paid on its due day, in cash, in full.
                CarbonImmutable::setTestNow($row->due_at->setTime(10, 0));

                $collector->collect(
                    $row,
                    $till,
                    (int) $row->amount,
                    $row->due_at->setTime(10, 0),
                    $this->owner,
                    'cash',
                );
            }
        }

        // A plan that ran its course: a handset sold four months ago on three instalments,
        // every row collected — the register needs one «تسویه‌شده» beside the late ones.
        $this->clockAt(120, 11);
        $settled = $this->sellHandset(customerIndex: 9, salespersonIndex: 1, paidToman: 15_000_000);
        $settledPlan = $creator->fromInvoice(
            invoice: $settled,
            count: 3,
            profitPercent: 2,
            firstDueAt: CarbonImmutable::now()->addDays(30)->startOfDay(),
            intervalMonths: 1,
            actorId: $this->owner,
        );

        foreach ($settledPlan->rows()->orderBy('sequence')->get() as $row) {
            CarbonImmutable::setTestNow($row->due_at->setTime(11, 0));
            $collector->collect($row, $till, (int) $row->amount, $row->due_at->setTime(11, 0), $this->owner, 'cash');
        }

        // And one opened ten days ago with nothing due yet — the desk's quiet row.
        $this->clockAt(10, 15);
        $fresh = $this->sellHandset(customerIndex: 12, salespersonIndex: 0, paidToman: 20_000_000);
        $creator->fromInvoice(
            invoice: $fresh,
            count: 6,
            profitPercent: 4,
            firstDueAt: CarbonImmutable::now()->addDays(30)->startOfDay(),
            intervalMonths: 1,
            actorId: $this->owner,
        );
    }

    /* ----------------------------------------------------------- the workshop -- */

    /**
     * Seventeen tickets across every column the board has.
     *
     * The approval gate is respected, not stepped around: a ticket only reaches «در دست
     * تعمیر» after `QuoteApproval` has recorded the customer's yes, which is what the
     * board's history shows when a card is opened. Two are «رسوبی» — ready for five weeks
     * and never collected — because that column is the one shopkeepers ask about first.
     *
     * @var list<array{0:string,1:string,2:string,3:int,4:int,5:string,6:int}>
     *                                                                         [device, fault, target status, technician ordinal, estimate toman, part sku or '', days ago]
     */
    private const TICKETS = [
        ['آیفون ۱۳', 'تعویض باتری — شارژ زیر ۲ ساعت می‌ماند', 'delivered', 0, 3_900_000, 'PRT-BAT-IP13', 18],
        ['گلکسی A55', 'شکستگی گلس و تاچ', 'delivered', 1, 6_800_000, 'PRT-LCD-A55', 12],
        ['ردمی نوت ۱۳ پرو', 'دوربین پشت تصویر نمی‌گیرد', 'ready', 0, 3_400_000, 'PRT-CAM-RN13', 6],
        ['آیفون ۱۵', 'سوکت شارژ لق شده', 'ready', 1, 1_200_000, 'PRT-SOCKET-C', 4],
        ['گلکسی S24 اولترا', 'صفحه‌نمایش خط افتاده', 'repairing', 1, 14_500_000, '', 3],
        ['پوکو X6 پرو', 'مشکل شارژ — کابل را نمی‌شناسد', 'repairing', 0, 900_000, 'PRT-SOCKET-C', 2],
        ['آیفون ۱۳', 'آب‌خوردگی؛ روشن نمی‌شود', 'repairing', 1, 5_500_000, '', 2],
        ['گلکسی A35', 'قطعی صدای اسپیکر', 'awaiting_parts', 0, 1_650_000, '', 5],
        ['آیفون ۱۶ پرو', 'دوربین جلو تار است', 'awaiting_approval', 1, 9_800_000, '', 1],
        ['ردمی نوت ۱۳ پرو', 'باتری باد کرده', 'awaiting_approval', 0, 2_900_000, '', 1],
        ['گلکسی A55', 'دکمهٔ پاور کار نمی‌کند', 'diagnosing', 1, 0, '', 1],
        ['آیفون ۱۵', 'شبکه پیدا نمی‌کند', 'diagnosing', 0, 0, '', 0],
        ['پوکو X6 پرو', 'صفحه‌نمایش شکسته', 'queued', 1, 0, '', 0],
        ['آیفون ۱۳', 'تعویض گلس', 'queued', 0, 0, '', 0],
        ['گلکسی S24 اولترا', 'بازیابی اطلاعات', 'queued', 1, 0, '', 0],
        ['آیفون ۱۵', 'تعویض باتری', 'abandoned', 0, 3_600_000, '', 52],
        ['گلکسی A35', 'تعویض گلس', 'abandoned', 1, 2_100_000, '', 44],
    ];

    private function seedRepairs(): void
    {
        $intake = app(TicketIntake::class);
        $machine = app(TicketStateMachine::class);
        $approval = app(QuoteApproval::class);
        $parts = app(TicketParts::class);
        $deliverer = app(DeliverTicket::class);

        $branch = Branch::query()->where('is_default', true)->firstOrFail();
        $warehouse = Warehouse::query()->where('branch_id', $branch->id)->where('is_sellable', true)->firstOrFail();
        $till = Account::query()->where('type', Account::TYPE_CASH)->orderByDesc('is_default')->firstOrFail();

        foreach (self::TICKETS as $ordinal => [$device, $fault, $target, $techOrdinal, $estimateToman, $partSku, $daysAgo]) {
            [$techOrdinal, $estimateToman, $daysAgo] = [(int) $techOrdinal, (int) $estimateToman, (int) $daysAgo];
            $technician = $this->technicians[$techOrdinal] ?? $this->owner;
            $customer = $this->customers[($ordinal * 5 + 2) % count($this->customers)];
            $intakeAt = $this->clockAt($daysAgo, 8 + ($ordinal % 5), ($ordinal * 11) % 60);

            // Promised for three days after intake; for the two «رسوبی» and one «ready»
            // that is now well in the past, which is what the late marker exists for.
            $promised = $intakeAt->addDays(3)->setTime(14, 0);

            $ticket = $intake->take([
                'branch_id' => $branch->id,
                'party_id' => $customer,
                'device_model' => $device,
                'reported_issue' => $fault,
                'technician_id' => $technician,
                'priority' => $ordinal % 6 === 0 ? RepairTicket::PRIORITY_URGENT : RepairTicket::PRIORITY_NORMAL,
                'promised_at' => $promised,
                'estimate_amount' => Money::fromToman($estimateToman),
                'prepaid_amount' => 0,
                'accessories' => $ordinal % 3 === 0 ? ['قاب', 'سیم‌کارت'] : [],
            ], $this->owner);

            if ($target === 'queued') {
                continue;
            }

            $this->clockAt($daysAgo, 12 + ($ordinal % 3));
            $ticket = $machine->transition($ticket, TicketStatus::Diagnosing, $technician, 'بررسی اولیه روی میز');

            if ($target === 'diagnosing') {
                continue;
            }

            // Every ticket past diagnosis has a quote on it; the customer's answer is what
            // separates the next three columns.
            $ticket = $approval->request($ticket, Money::fromToman($estimateToman), $technician);

            if ($target === 'awaiting_approval') {
                continue;
            }

            $this->clockAt(max($daysAgo - 1, 0), 9 + ($ordinal % 4));
            $ticket = $approval->approveByPhone($ticket, $this->owner, 'تأیید تلفنی مشتری');
            $ticket->refresh();

            if ($target === 'awaiting_parts') {
                $machine->transition($ticket, TicketStatus::AwaitingParts, $technician, 'قطعه سفارش داده شد');

                continue;
            }

            if ($ticket->status !== TicketStatus::Repairing) {
                $ticket = $machine->transition($ticket, TicketStatus::Repairing, $technician, 'شروع تعمیر');
            }

            $partsBilled = 0;

            if ($partSku !== '') {
                $variant = ProductVariant::query()->where('sku', $partSku)->firstOrFail();
                $partPrice = $this->retailFor($variant->id);
                $part = $parts->reserve($ticket, $variant->id, (int) $warehouse->id, 1, $partPrice, $technician);
                $parts->consume($part, $technician);
                $partsBilled = $partPrice;
            }

            if ($target === 'repairing') {
                continue;
            }

            $this->clockAt(max($daysAgo - 2, 0), 15);
            $ticket = $machine->transition($ticket, TicketStatus::Ready, $technician, 'تعمیر انجام شد؛ تست نهایی OK');

            if ($target === 'ready') {
                continue;
            }

            if ($target === 'abandoned') {
                // Five weeks on the shelf, then swept.
                $this->clockAt(max($daysAgo - 37, 1), 9);
                $machine->transition($ticket, TicketStatus::Abandoned, $this->owner, 'مشتری با وجود سه بار تماس مراجعه نکرد');

                continue;
            }

            // Delivered, with the labour billed and the parts the bench consumed billed
            // beside it — the same call the deliver screen makes.
            $this->clockAt(max($daysAgo - 3, 0), 17);
            $labour = Money::fromToman($estimateToman) - $partsBilled;
            $labourLines = [['description' => 'اجرت تعمیر', 'amount' => max($labour, Money::fromToman(300_000))]];

            try {
                $deliverer->deliver(
                    $ticket,
                    $labourLines,
                    [['method' => PaymentMethod::Cash->value, 'amount' => $labourLines[0]['amount'] + $partsBilled, 'account_id' => $till->id]],
                    warrantyDays: 90,
                    actorId: $this->owner,
                );
            } catch (RuntimeException) {
                // VAT settings can put the invoice above labour + parts; pay the labour and
                // leave the difference on the customer's account rather than guess the tax.
                $deliverer->deliver(
                    $ticket->refresh(),
                    $labourLines,
                    [['method' => PaymentMethod::Cash->value, 'amount' => $labourLines[0]['amount'], 'account_id' => $till->id]],
                    warrantyDays: 90,
                    actorId: $this->owner,
                );
            }
        }
    }

    /* ------------------------------------------------------------- the cheques -- */

    /**
     * Ten cheques, so the register shows every column it has: due this week, one already
     * overdue and still in the drawer, two banked, one bounced and chased, and two the
     * shop wrote itself.
     */
    private function seedCheques(): void
    {
        $register = app(RegisterCheque::class);
        $transitions = app(ChequeTransitions::class);
        $bank = Account::query()->where('type', Account::TYPE_BANK)->firstOrFail();
        $branchId = Branch::query()->where('is_default', true)->firstOrFail()->id;

        $suppliers = Party::query()->where('kind', 'supplier')->orderBy('id')->get()->map(fn (Party $party): int => $party->id)->all();

        // [customer ordinal, bank, serial, amount toman, received days ago, due in days (negative = past), fate]
        foreach ([
            [1, 'ملت', '338201', 28_000_000, 20, -3, 'in_hand'],
            [3, 'صادرات', '552914', 41_500_000, 14, 2, 'in_hand'],
            [6, 'پاسارگاد', '104477', 19_000_000, 9, 5, 'in_hand'],
            [13, 'ملی', '760210', 62_000_000, 6, 12, 'in_hand'],
            [0, 'تجارت', '981133', 15_500_000, 4, 20, 'in_hand'],
            [8, 'ملت', '338977', 33_000_000, 2, 40, 'in_hand'],
            [4, 'سامان', '210455', 24_000_000, 26, -15, 'cleared'],
            [10, 'صادرات', '553108', 18_000_000, 24, -9, 'bounced'],
        ] as $ordinal => [$customer, $bankName, $serial, $toman, $receivedDaysAgo, $dueIn, $fate]) {
            $receivedAt = $this->clockAt($receivedDaysAgo, 11, ($ordinal * 13) % 60);

            $cheque = $register->register(ChequeDirection::Received, [
                'party_id' => $this->customers[$customer],
                'amount' => Money::fromToman($toman),
                'serial' => $serial,
                'sayad_id' => str_pad((string) (4_000_000_000_000_000 + $ordinal * 1_234_567), 16, '0', STR_PAD_LEFT),
                'bank_name' => $bankName,
                'due_date' => $this->origin->addDays($dueIn)->startOfDay(),
                'received_at' => $receivedAt,
                'branch_id' => $branchId,
            ], null, $this->owner);

            if ($fate === 'cleared') {
                $dueAt = $this->origin->addDays($dueIn)->setTime(9, 0);
                CarbonImmutable::setTestNow($dueAt);
                $transitions->deposit($cheque, $bank, $dueAt, $this->owner);
                $transitions->clear($cheque, null, 0, $dueAt->addDay(), $this->owner);
            }

            if ($fate === 'bounced') {
                $dueAt = $this->origin->addDays($dueIn)->setTime(9, 0);
                CarbonImmutable::setTestNow($dueAt);
                $transitions->deposit($cheque, $bank, $dueAt, $this->owner);
                $transitions->bounce($cheque, 'کسر موجودی', 0, Money::fromToman(30_000), $dueAt->addDay(), $this->owner);
            }
        }

        // Two the shop wrote to its suppliers, drawn on the bank: one cleared, one still out.
        foreach ([
            [0, 'ملت', '000117', 185_000_000, 22, -20, 'cleared'],
            [1, 'ملت', '000118', 96_000_000, 5, 10, 'issued'],
        ] as $ordinal => [$supplier, $bankName, $serial, $toman, $issuedDaysAgo, $dueIn, $fate]) {
            $issuedAt = $this->clockAt($issuedDaysAgo, 10);

            $cheque = $register->register(ChequeDirection::Issued, [
                'party_id' => $suppliers[$supplier] ?? $suppliers[0],
                'amount' => Money::fromToman($toman),
                'serial' => $serial,
                'bank_name' => $bankName,
                'due_date' => $this->origin->addDays($dueIn)->startOfDay(),
                'received_at' => $issuedAt,
                'branch_id' => $branchId,
            ], $bank, $this->owner);

            if ($fate === 'cleared') {
                $dueAt = $this->origin->addDays($dueIn)->setTime(9, 0);
                CarbonImmutable::setTestNow($dueAt);
                $transitions->markPresented($cheque, $dueAt, $this->owner);
                $transitions->clearIssued($cheque, 0, $dueAt->addDay(), $this->owner);
            }
        }
    }

    /* ------------------------------------------------------------ the treasury -- */

    /**
     * The month's overheads and the banking of its takings.
     *
     * Rent, the utility bill and wages go out through `RecordCashTransaction`, which is
     * what fills the daily close's expense breakdown; the till is banked weekly and the
     * card terminal settles net of the PSP's cut — the shape `CrazyMonthSeeder` proved.
     */
    private function seedTreasury(): void
    {
        $recorder = app(RecordCashTransaction::class);
        $transfers = app(TransferBetweenAccounts::class);

        $till = Account::query()->where('type', Account::TYPE_CASH)->orderByDesc('is_default')->firstOrFail();
        $bank = Account::query()->where('type', Account::TYPE_BANK)->firstOrFail();
        $terminal = Account::query()->where('type', Account::TYPE_POS_TERMINAL)->firstOrFail();

        foreach ([
            ['اجاره مغازه', 60_000_000, 28, 'اجارهٔ شهریور'],
            ['حقوق کارکنان', 48_000_000, 27, 'حقوق مرداد'],
            ['قبض برق و آب', 4_200_000, 16, 'قبض دورهٔ ۴'],
            ['ملزومات فروشگاه', 1_350_000, 11, 'رول حرارتی و کیسه'],
        ] as $ordinal => [$heading, $toman, $daysAgo, $description]) {
            $expense = Account::query()->firstOrCreate(
                ['type' => Account::TYPE_EXPENSE, 'name' => $heading],
                ['is_active' => true],
            );

            $category = TransactionCategory::query()->firstOrCreate(
                ['name' => $heading, 'direction' => CashDirection::Expense->value],
                ['account_id' => $expense->id, 'is_active' => true],
            );

            $at = $this->clockAt($daysAgo, 13, $ordinal * 9);

            $recorder->record(
                $category,
                $toman >= 10_000_000 ? $bank : $till,
                Money::fromToman($toman),
                $at,
                description: $description,
                actorId: $this->owner,
            );
        }

        // Weekly banking of cash, and the terminal settling net of its fee.
        foreach ([[24, 25_000_000], [17, 30_000_000], [10, 28_000_000], [3, 22_000_000]] as [$daysAgo, $toman]) {
            $at = $this->clockAt($daysAgo, 16);
            $transfers->transfer($till, $bank, Money::fromToman($toman), reference: 'واریز نقدی هفتگی', occurredAt: $at, actorId: $this->owner);
        }

        foreach ([[19, 120_000_000, 850_000], [5, 140_000_000, 990_000]] as [$daysAgo, $toman, $feeToman]) {
            $at = $this->clockAt($daysAgo, 9);
            $transfers->transfer($terminal, $bank, Money::fromToman($toman), fee: Money::fromToman($feeToman), reference: 'تسویه کارتخوان', occurredAt: $at, actorId: $this->owner);
        }
    }

    /* ------------------------------------------------------------ the messages -- */

    /**
     * A message log with all three states on it.
     *
     * The wallet is topped up first — a shop that has not bought credit sends nothing —
     * and the fake driver accepts everything else, so the «آماده است» and reminder rows
     * land as `sent`. One goes to a number with no digits in it, which is the honest way
     * to get a `suppressed` row rather than inventing a provider failure.
     */
    private function seedMessages(): void
    {
        $this->clockAt(26, 10);
        app(SmsWallet::class)->topUp(Money::fromToman(1_500_000), 'خرید بستهٔ پیامک', $this->owner);

        $sender = app(SendSms::class);

        $delivered = RepairTicket::query()
            ->where('status', TicketStatus::Delivered)
            ->orderBy('id')
            ->get();

        foreach ($delivered as $index => $ticket) {
            $this->clockAt(max((int) $ticket->delivered_at?->diffInDays($this->origin), 0), 14, $index * 5);
            $sender->send(
                $this->mobileOf((int) $ticket->party_id),
                'repair-ready',
                ['موبایل دمو', (string) $ticket->code],
                'repair.ready',
                (int) $ticket->party_id,
                'showcase-ready-'.$ticket->id,
                $ticket,
            );
        }

        foreach ([[6, 3], [4, 1], [2, 6]] as $index => [$daysAgo, $customer]) {
            $this->clockAt($daysAgo, 9, $index * 7);
            $sender->send(
                $this->mobileOf($this->customers[$customer]),
                'installment-reminder',
                ['موبایل دمو', 'فردا'],
                'installment.reminder',
                $this->customers[$customer],
                'showcase-reminder-'.$index,
            );
        }

        // No digits, no number — recorded as suppressed with the reason, never sent.
        $this->clockAt(1, 11);
        $sender->send('—', 'invoice-thanks', ['موبایل دمو'], 'invoice.thanks', $this->customers[7], 'showcase-suppressed');
    }

    private function mobileOf(int $partyId): ?string
    {
        return PartyContact::query()
            ->where('party_id', $partyId)
            ->where('type', PartyContact::TYPE_MOBILE)
            ->orderByDesc('is_primary')
            ->first()
            ?->value;
    }

    /* -------------------------------------------------------------- helpers -- */

    /**
     * A party, with its credit spent — the same three lines `PartyController::store` runs.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeParty(array $attributes, string $contact, string $contactType = PartyContact::TYPE_MOBILE): Party
    {
        return DB::transaction(function () use ($attributes, $contact, $contactType): Party {
            app(QuotaGuard::class)->consume('crm.parties');

            /** @var Party $party */
            $party = Party::query()->create($attributes);

            $party->contacts()->create([
                'type' => $contactType,
                'value' => $contact,
                'is_primary' => true,
            ]);

            return $party;
        });
    }

    /**
     * A product, with its credit spent — what `ProductController::store` does.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeProduct(array $attributes): Product
    {
        return DB::transaction(function () use ($attributes): Product {
            app(QuotaGuard::class)->consume('catalog.products');

            /** @var Product $product */
            $product = Product::query()->create($attributes);

            return $product;
        });
    }

    /** A standard (non-serialized) line with its single variant. */
    private function makeStandardLine(string $name, string $sku, string $barcode, int $threshold, int $categoryId): ProductVariant
    {
        $product = $this->makeProduct([
            'name' => $name,
            'type' => 'standard',
            'category_id' => $categoryId,
            'low_stock_threshold' => $threshold,
        ]);

        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'options' => [],
            'barcode' => $barcode,
            'sku' => $sku,
        ]);

        return $variant;
    }

    /**
     * One delivery, received for real through `ReceivePurchaseInvoice`.
     *
     * Handsets cycle through every model so the shelf carries the whole range; each unit's
     * landed cost sits a few percent under the model's trade price, which is what makes the
     * profit report a margin rather than a guess.
     *
     * @param  array<string, array{0:int,1:int}>  $quantities  sku => [quantity, unit cost in toman]
     */
    private function receiveShipment(Warehouse $warehouse, int $supplierId, string $number, int $handsets, array $quantities): void
    {
        $invoice = PurchaseInvoice::query()->create([
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'party_id' => $supplierId,
            'number' => $number,
            'status' => PurchaseInvoice::STATUS_DRAFT,
            'issued_at' => now()->subDay(),
        ]);

        $total = 0;

        foreach ($quantities as $sku => [$quantity, $costToman]) {
            $variant = ProductVariant::query()->where('sku', $sku)->firstOrFail();
            $unitCost = Money::fromToman($costToman);

            PurchaseInvoiceItem::query()->create([
                'purchase_invoice_id' => $invoice->id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_total' => $unitCost * $quantity,
            ]);

            $total += $unitCost * $quantity;
        }

        $models = self::HANDSETS;

        foreach (range(0, $handsets - 1) as $index) {
            [, , $sku, , $trade] = $models[$index % count($models)];
            $variant = ProductVariant::query()->where('sku', $sku)->firstOrFail();
            // 94–97% of the trade price, varying by ordinal so two units of one model do
            // not share a cost to the rial.
            $unitCost = Money::fromToman((int) round($trade * (0.94 + ($index % 4) * 0.01)));

            PurchaseUnitItem::query()->create([
                'purchase_invoice_id' => $invoice->id,
                'product_variant_id' => $variant->id,
                'imei1' => $this->imei(),
                'condition' => 'new',
                'unit_cost' => $unitCost,
            ]);

            $total += $unitCost;
        }

        $invoice->update(['subtotal' => $total, 'total' => $total]);

        app(ReceivePurchaseInvoice::class)->receive($invoice, now()->toImmutable());
    }

    /** What the screens will show — printed so the person capturing them can sanity-check. */
    private function report(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo')->firstOrFail();

        $counts = app(TenantContext::class)->runFor($tenant, fn (): array => [
            'invoices' => SalesInvoice::query()->count(),
            'units' => ProductUnit::query()->count(),
            'parties' => Party::query()->count(),
            'tickets' => RepairTicket::query()->count(),
            'plans' => InstallmentPlan::query()->count(),
        ]);

        app(TenantContext::class)->forget();

        $this->command?->newLine();
        $this->command?->info('  ShowcaseSeeder — the demo shop now has:');

        foreach ($counts as $label => $count) {
            $this->command?->info(sprintf('    %-10s %d', $label, $count));
        }
    }

    /**
     * Park the clock at a fixed moment, so every service stamps the date it would have.
     *
     * Hours are UTC and kept between 07:00 and 16:00 so that the Tehran wall clock — which
     * is what every report groups by — lands inside the same working day. Restored in
     * `run()`'s `finally`.
     */
    private function clockAt(int $daysAgo, int $hour, int $minute = 0): CarbonImmutable
    {
        $at = $this->origin->subDays($daysAgo)->setTime($hour, $minute);

        CarbonImmutable::setTestNow($at);

        return $at;
    }

    /** @param array<string, mixed> $attributes */
    private function accountOfType(string $type, string $name, array $attributes = []): Account
    {
        $account = Account::query()->where('type', $type)->orderByDesc('is_default')->orderBy('id')->first();

        if ($account instanceof Account) {
            return $account;
        }

        /** @var Account $created */
        $created = Account::query()->create([
            'type' => $type,
            'name' => $name,
            'is_active' => true,
            ...$attributes,
        ]);

        return $created;
    }

    private function imei(): string
    {
        $body = '35'.Str::padLeft((string) mt_rand(0, 999_999_999), 12, '0');

        return $body.Imei::checkDigitFor($body);
    }
}
