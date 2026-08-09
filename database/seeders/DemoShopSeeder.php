<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\Catalog\Services\VariantMatrix;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\UnitStateMachine;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use App\Modules\Purchasing\Models\PurchaseUnitItem;
use App\Modules\Purchasing\Services\ReceivePurchaseInvoice;
use App\Support\Imei;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A shop with something in it — `make fresh`.
 *
 * Built by driving the real services rather than by inserting rows: the purchase is
 * *received* through `ReceivePurchaseInvoice`, so the demo database has correct landed
 * costs, real stock movements, a supplier credit in the ledger and IMEI passports that
 * begin where they should. Hand-inserted fixtures look right on a list screen and fall
 * apart on the first page that reconciles anything.
 */
class DemoShopSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo')->first();

        if (! $tenant instanceof Tenant) {
            return;
        }

        app(TenantContext::class)->runFor($tenant, function (): void {
            $owner = User::query()->orderBy('id')->firstOrFail();

            // Seeding acts as the owner: every passport line records who did it, and
            // "unknown" on every row of a demo is a poor advertisement for an audit
            // trail.
            auth()->setUser($owner);

            $this->seedTree();
            $this->seedParties();
            $this->seedShipment();
            $this->seedDeviceLives();
        });

        app(TenantContext::class)->forget();
    }

    private function seedTree(): void
    {
        $phones = Category::query()->create(['name' => 'گوشی موبایل', 'slug' => 'گوشی-موبایل', 'position' => 1]);
        Category::query()->create(['name' => 'اپل', 'slug' => 'اپل', 'parent_id' => $phones->id, 'position' => 1]);
        Category::query()->create(['name' => 'سامسونگ', 'slug' => 'سامسونگ', 'parent_id' => $phones->id, 'position' => 2]);
        Category::query()->create(['name' => 'لوازم جانبی', 'slug' => 'لوازم-جانبی', 'position' => 2]);

        foreach ([['Apple', 'اپل'], ['Samsung', 'سامسونگ'], ['Xiaomi', 'شیائومی']] as $index => [$name, $fa]) {
            Brand::query()->create(['name' => $name, 'name_fa' => $fa, 'position' => $index]);
        }
    }

    private function seedParties(): void
    {
        $supplier = Party::query()->create([
            'kind' => 'supplier',
            'name' => 'پخش قطعات جنوب شرق تهران',
            'company_name' => 'شرکت تجارت الکترونیک آریا',
            'opening_balance' => 0,
        ]);

        $supplier->contacts()->create([
            'type' => PartyContact::TYPE_PHONE,
            'value' => '02133445566',
            'is_primary' => true,
        ]);

        foreach ([
            ['محمدرضا کریمی‌نژاد', 'colleague', '09121112233', Money::fromToman(12_850_000)],
            ['سمیرا احمدی', 'customer', '09351234567', 0],
            ['حسین موسوی', 'customer', '09190001122', Money::fromToman(-4_620_000)],
        ] as [$name, $kind, $mobile, $opening]) {
            $party = Party::query()->create([
                'kind' => $kind,
                'name' => $name,
                'opening_balance' => $opening,
            ]);

            $party->contacts()->create([
                'type' => PartyContact::TYPE_MOBILE,
                'value' => $mobile,
                'is_primary' => true,
            ]);
        }
    }

    /**
     * One shipment: ten handsets and a box of accessories, received for real.
     */
    private function seedShipment(): void
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $supplier = Party::query()->where('kind', 'supplier')->firstOrFail();

        $level = PriceLevel::query()->where('is_default', true)->firstOrFail();
        $reseller = PriceLevel::query()->where('code', PriceLevel::RESELLER)->first() ?? $level;

        $apple = Brand::query()->where('name', 'Apple')->firstOrFail();
        $appleCategory = Category::query()->where('name', 'اپل')->firstOrFail();
        $accessories = Category::query()->where('name', 'لوازم جانبی')->firstOrFail();

        $phone = Product::query()->create([
            'name' => 'آیفون ۱۵ پرو مکس',
            'type' => 'serialized',
            'brand_id' => $apple->id,
            'category_id' => $appleCategory->id,
        ]);

        $phoneVariants = app(VariantMatrix::class)->generate($phone, [
            'رنگ' => ['تیتانیوم طبیعی', 'تیتانیوم مشکی'],
            'حافظه' => ['256', '512'],
        ]);

        $prices = app(PriceResolver::class);

        foreach ($phoneVariants as $index => $variant) {
            $prices->setPrice($variant->id, $level->id, Money::fromToman(82_000_000 + $index * 6_000_000));
            $prices->setPrice($variant->id, $reseller->id, Money::fromToman(79_500_000 + $index * 6_000_000));
        }

        $charger = Product::query()->create([
            'name' => 'شارژر ۲۰ وات اورجینال',
            'type' => 'standard',
            'category_id' => $accessories->id,
            // The two lines a shop actually wants to be warned about.
            'low_stock_threshold' => 5,
        ]);

        $chargerVariant = ProductVariant::query()->create([
            'product_id' => $charger->id,
            'options' => [],
            'barcode' => '6260000000019',
            'sku' => 'ACC-CHG-20W',
        ]);

        $prices->setPrice($chargerVariant->id, $level->id, Money::fromToman(450_000));

        $case = Product::query()->create([
            'name' => 'قاب محافظ شفاف',
            'type' => 'standard',
            'category_id' => $accessories->id,
            'low_stock_threshold' => 10,
        ]);

        $caseVariant = ProductVariant::query()->create([
            'product_id' => $case->id,
            'options' => [],
            'barcode' => '6260000000026',
            'sku' => 'ACC-CASE-CLR',
        ]);

        $prices->setPrice($caseVariant->id, $level->id, Money::fromToman(180_000));

        $invoice = PurchaseInvoice::query()->create([
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'party_id' => $supplier->id,
            'number' => 'PUR-1405-0012',
            'status' => PurchaseInvoice::STATUS_DRAFT,
            'issued_at' => now()->subDays(21),
        ]);

        foreach ([[$chargerVariant, 12, 320_000], [$caseVariant, 4, 95_000]] as [$variant, $quantity, $toman]) {
            $unitCost = Money::fromToman($toman);

            PurchaseInvoiceItem::query()->create([
                'purchase_invoice_id' => $invoice->id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'line_total' => $unitCost * $quantity,
            ]);
        }

        // Ten handsets, spread across the matrix, each with a Luhn-valid IMEI — the
        // passport is worthless if the numbers on it would fail validation.
        foreach (range(0, 9) as $index) {
            $variant = $phoneVariants[$index % count($phoneVariants)];

            PurchaseUnitItem::query()->create([
                'purchase_invoice_id' => $invoice->id,
                'product_variant_id' => $variant->id,
                'imei1' => $this->imei(),
                'condition' => 'new',
                'unit_cost' => Money::fromToman(76_000_000 + $index * 500_000),
            ]);
        }

        $invoice->update([
            'subtotal' => Money::fromToman(12 * 320_000 + 4 * 95_000 + 10 * 76_000_000),
            'total' => Money::fromToman(12 * 320_000 + 4 * 95_000 + 10 * 76_000_000),
        ]);

        app(ReceivePurchaseInvoice::class)->receive($invoice, now()->subDays(20)->toImmutable());
    }

    /**
     * Give a few devices a story, so the passport has something to tell.
     */
    private function seedDeviceLives(): void
    {
        $machine = app(UnitStateMachine::class);

        /** @var list<ProductUnit> $units */
        $units = ProductUnit::query()->orderBy('id')->limit(4)->get()->all();

        if (count($units) < 4) {
            return;
        }

        [$reserved, $repaired, $sold, $pendingHamta] = $units;

        // Reserved for a named customer, then released.
        $machine->transition($reserved, UnitStatus::Reserved, null, 'رزرو برای آقای کریمی‌نژاد');
        $machine->transition($reserved, UnitStatus::InStock, null, 'مشتری منصرف شد');

        // On the bench and back.
        $machine->transition($repaired, UnitStatus::InRepair, null, 'بررسی لرزش دوربین پیش از فروش');
        $machine->transition($repaired, UnitStatus::InStock, null, 'ایراد تأیید نشد');

        $machine->transition($sold, UnitStatus::Reserved, null, 'رزرو تلفنی');
        $machine->transition($sold, UnitStatus::Sold, null, 'فروش نقدی');

        $pendingHamta->update(['hamta_status' => ProductUnit::HAMTA_PENDING]);
    }

    private function imei(): string
    {
        $body = '35'.Str::padLeft((string) random_int(0, 999_999_999_999), 12, '0');

        return $body.Imei::checkDigitFor($body);
    }
}
