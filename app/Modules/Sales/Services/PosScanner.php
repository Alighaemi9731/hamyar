<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\StockReservations;
use App\Support\Imei;
use Illuminate\Support\Collection;

/**
 * One box, any code. What the counter actually scans.
 *
 * ## Why this is not two pickers
 *
 * The catalogue has two kinds of thing behind it — a handset, which is one specific
 * device with an IMEI, and a charger, which is a quantity of an interchangeable line.
 * The software cares about that distinction enormously; the person holding the scanner
 * does not. They point it at whatever is in their hand.
 *
 * So the POS has a single field and this class works out what came out of it. Asking a
 * salesperson to first choose "serialized" or "standard" and then scan is asking them to
 * know our schema, and it costs a second on every line of every sale.
 *
 * ## Exact before fuzzy, always
 *
 * A scan is not a search. When a code matches an IMEI, a serial, a barcode or an SKU
 * **exactly**, that is the answer and nothing else is offered — a fifteen-digit number
 * that also appears inside some product's name must never turn a finished scan into a
 * list. Only when nothing matches exactly does this fall back to a name search, which is
 * the typed-by-hand case.
 *
 * ## Sold-out and sold-already are answers, not absences
 *
 * A handset that is already sold, reserved or on a repair bench resolves to a candidate
 * carrying its status and marked unsellable, rather than to an empty result. "This phone
 * was sold yesterday by Reza" is something the salesperson can act on; "not found" makes
 * them scan it three more times and then check the shelf.
 */
final class PosScanner
{
    /** Enough to choose from; past this the term is too vague to be a scan. */
    private const LIMIT = 10;

    public function __construct(
        private readonly PriceResolver $prices,
        private readonly StockLedger $stock,
        private readonly StockReservations $reservations,
    ) {}

    /**
     * Resolve a scanned or typed code into things that could be added to the basket.
     *
     * @return list<array<string, mixed>> ordered best-first; empty when nothing matched
     */
    public function resolve(string $code, int $branchId, ?int $priceLevelId = null, bool $showCost = false): array
    {
        $code = trim($code);

        if ($code === '') {
            return [];
        }

        $warehouseId = $this->warehouseIdFor($branchId);

        $exact = $this->exactMatches($code, $warehouseId, $priceLevelId, $showCost);

        if ($exact !== []) {
            return $exact;
        }

        return $this->nameMatches($code, $warehouseId, $priceLevelId, $showCost);
    }

    /**
     * A code that IS an identifier — the scanner's normal case.
     *
     * @return list<array<string, mixed>>
     */
    private function exactMatches(string $code, ?int $warehouseId, ?int $priceLevelId, bool $showCost): array
    {
        $units = ProductUnit::query()
            ->with(['variant.product', 'warehouse'])
            ->matchingCode($code)
            ->limit(self::LIMIT)
            ->get();

        // Digits normalised, so an IMEI typed on a Persian keypad and one read off the
        // box find the same handset (golden rule: `Imei::normalise` on both ends).
        $normalised = Imei::normalise($code);

        $variants = ProductVariant::query()
            ->with('product')
            ->active()
            ->where(function ($query) use ($code, $normalised): void {
                $query->where('barcode', $code)->orWhere('sku', $code);

                if ($normalised !== '' && $normalised !== $code) {
                    $query->orWhere('barcode', $normalised);
                }
            })
            ->limit(self::LIMIT)
            ->get();

        return [
            ...$units->map(fn (ProductUnit $unit): array => $this->unitCandidate($unit, $priceLevelId, $showCost))->all(),
            ...$variants->map(fn (ProductVariant $variant): array => $this->variantCandidate($variant, $warehouseId, $priceLevelId, $showCost))->all(),
        ];
    }

    /**
     * Nobody scanned anything — somebody is typing a name.
     *
     * @return list<array<string, mixed>>
     */
    private function nameMatches(string $term, ?int $warehouseId, ?int $priceLevelId, bool $showCost): array
    {
        $variants = ProductVariant::query()
            ->with('product')
            ->active()
            ->whereHas('product', fn ($query) => $query->where('name', 'ilike', "%{$term}%"))
            ->limit(self::LIMIT)
            ->get();

        // Handsets of a matching model, but only ones that can actually be sold: a name
        // search is browsing, and offering somebody else's sold phone as a suggestion is
        // noise rather than the useful answer an exact scan gives.
        $units = ProductUnit::query()
            ->with(['variant.product', 'warehouse'])
            ->where('status', UnitStatus::InStock->value)
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->whereHas('variant.product', fn ($query) => $query->where('name', 'ilike', "%{$term}%"))
            ->limit(self::LIMIT)
            ->get();

        return [
            ...$units->map(fn (ProductUnit $unit): array => $this->unitCandidate($unit, $priceLevelId, $showCost))->all(),
            ...$this->withoutSerialized($variants)
                ->map(fn (ProductVariant $variant): array => $this->variantCandidate($variant, $warehouseId, $priceLevelId, $showCost))
                ->all(),
        ];
    }

    /**
     * Drop the variants whose handsets are already listed individually.
     *
     * A serialized variant is not sellable as a quantity — the line has to name a device
     * — so offering "iPhone 15 Pro, 256GB" beside the three actual handsets would give
     * the operator a choice that the basket cannot honour.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return Collection<int, ProductVariant>
     */
    private function withoutSerialized(Collection $variants): Collection
    {
        return $variants->filter(fn (ProductVariant $variant): bool => ! $variant->product->isSerialized());
    }

    /**
     * @return array<string, mixed>
     */
    private function unitCandidate(ProductUnit $unit, ?int $priceLevelId, bool $showCost): array
    {
        $sellable = $unit->status === UnitStatus::InStock;

        return [
            'key' => "unit:{$unit->id}",
            'kind' => 'unit',
            'unit_id' => $unit->id,
            'variant_id' => $unit->product_variant_id,
            'product_name' => $unit->variant->product->name,
            'variant_name' => $unit->variant->displayName(),
            'description' => $this->unitDescription($unit),
            'imei' => $unit->imei1 ?? $unit->serial,
            'status' => $unit->status->value,
            'sellable' => $sellable,
            // Named in the shop's own words, so the reason a scan did not add a line is
            // readable without translating a status code.
            'blocked_reason' => $sellable ? null : $this->blockedReason($unit),
            'condition_label' => $unit->condition->labelFa(),
            'grade' => $unit->grade,
            'warehouse_name' => $unit->warehouse?->name,
            'unit_price' => $this->prices->priceFor($unit->product_variant_id, $priceLevelId) ?? 0,
            // Specific identity: this device's own cost, not the line's average.
            'cost' => $showCost ? $unit->cost : null,
            'on_hand' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variantCandidate(ProductVariant $variant, ?int $warehouseId, ?int $priceLevelId, bool $showCost): array
    {
        /*
        | AVAILABLE, not on-hand. A screen protector set aside for tomorrow's repair is
        | physically on the shelf, so `onHand()` counts it — and a till quoting on-hand
        | will sell it out from under the bench. The technician then opens the drawer to
        | a gap and the shop has two unhappy customers.
        */
        $onHand = $warehouseId === null ? 0 : $this->reservations->available($variant->id, $warehouseId);

        return [
            'key' => "variant:{$variant->id}",
            'kind' => 'variant',
            'unit_id' => null,
            'variant_id' => $variant->id,
            'product_name' => $variant->product->name,
            'variant_name' => $variant->displayName(),
            'description' => $this->describe($variant->product->name, $variant->displayName()),
            'imei' => null,
            'status' => null,
            // Zero available is NOT unsellable here: whether a shop may sell into
            // negative stock is a per-warehouse setting the ledger owns (Phase 3.4), and
            // second-guessing it at the till would block a sale the shop has allowed.
            // The figure is shown so the operator decides with their eyes open — and it
            // is net of repair reservations, so what it shows is what may be promised.
            'sellable' => true,
            'blocked_reason' => null,
            'condition_label' => null,
            'grade' => null,
            'warehouse_name' => null,
            'unit_price' => $this->prices->priceFor($variant->id, $priceLevelId) ?? 0,
            'cost' => $showCost ? $this->stock->weightedAverageCost($variant->id, $warehouseId) : null,
            'on_hand' => $onHand,
        ];
    }

    /**
     * What the invoice line will say it sold.
     *
     * The IMEI is deliberately **not** in here. The line stores `product_unit_id`, and
     * every screen and print layout renders the device's code from that — so putting it
     * in the description too printed it twice on the invoice, once inside the product
     * name and once underneath it. Caught in a browser pass, not by a test.
     */
    private function unitDescription(ProductUnit $unit): string
    {
        return $this->describe($unit->variant->product->name, $unit->variant->displayName());
    }

    /**
     * Product name plus what distinguishes this variant, without saying either twice.
     *
     * `ProductVariant::displayName()` falls back to the product's own name when a line
     * has no options — a charger, most accessories — so concatenating blindly produced
     * «شارژر ۲۰ وات اورجینال شارژر ۲۰ وات اورجینال» on the invoice.
     */
    private function describe(string $product, string $variant): string
    {
        $variant = trim($variant);

        return $variant === '' || $variant === $product
            ? $product
            : "{$product} — {$variant}";
    }

    private function blockedReason(ProductUnit $unit): string
    {
        return match ($unit->status) {
            UnitStatus::Sold => 'این دستگاه قبلاً فروخته شده است.',
            UnitStatus::Reserved => 'این دستگاه برای مشتری دیگری رزرو شده است.',
            UnitStatus::InRepair => 'این دستگاه روی میز تعمیر است.',
            UnitStatus::Returned => 'این دستگاه مرجوع شده و هنوز بازبینی نشده است.',
            UnitStatus::WrittenOff => 'این دستگاه از موجودی خارج شده است.',
            UnitStatus::InStock => '',
        };
    }

    /**
     * The branch's sellable warehouse — the same one finalisation will take stock from.
     *
     * Resolved here so the on-hand figure the operator sees is the figure the sale will
     * actually consume. Reading a different warehouse would show stock that the sale
     * cannot use.
     */
    private function warehouseIdFor(int $branchId): ?int
    {
        $id = Warehouse::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->orderByDesc('is_default')
            ->value('id');

        return is_int($id) ? $id : null;
    }
}
