<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockCountService;
use App\Support\Counters\CounterService;
use App\Support\Digits;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Counting what is actually on the shelf.
 *
 * **Blind by default**, and that default is the whole point: a number on the screen is
 * a number people count towards. The expected figure is withheld from the counter and
 * only appears once counting is done, on the variance review — which is where it is
 * useful and where it can no longer bias anyone.
 *
 * Applying a count writes the *difference* as an adjustment movement. It never sets a
 * total, so "we were three short in Mordad" stays answerable forever.
 */
final class StockCountController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', ProductUnit::class);

        $counts = StockCount::query()
            ->with('warehouse:id,name')
            ->withCount('items')
            ->orderByDesc('id')
            ->paginate(25);

        return Inertia::render('Inventory::Counts/Index', [
            'counts' => [
                'rows' => array_map(fn (StockCount $count): array => [
                    'id' => $count->id,
                    'number' => $count->number,
                    'status' => $count->status,
                    'warehouse' => $count->warehouse->name,
                    'is_blind' => $count->is_blind,
                    'line_count' => $this->countOf($count, 'items_count'),
                    'applied_at' => $count->applied_at?->toIso8601String(),
                ], $counts->items()),
                'links' => $counts->linkCollection()->toArray(),
                'total' => $counts->total(),
            ],
            'warehouses' => $this->warehouseOptions(),
        ]);
    }

    public function store(
        Request $request,
        ConnectionInterface $connection,
        CounterService $counters,
        TenantContext $context,
    ): RedirectResponse {
        $this->authorize('adjust', ProductUnit::class);

        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'is_blind' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $tenantId = $context->id();

        if ($tenantId === null) {
            abort(404);
        }

        /** @var StockCount $count */
        $count = $connection->transaction(fn (): StockCount => StockCount::query()->create([
            'warehouse_id' => $validated['warehouse_id'],
            'number' => $counters->nextFormatted($tenantId, 'stock_count', 'CNT'),
            'status' => StockCount::STATUS_OPEN,
            'is_blind' => $validated['is_blind'] ?? true,
            'notes' => $validated['notes'] ?? null,
            'actor_id' => $request->user()?->id,
        ]));

        return redirect()
            ->route('inventory.counts.show', $count)
            ->with('success', "انبارگردانی {$count->number} باز شد.");
    }

    public function show(Request $request, StockCount $count, StockCountService $service): Response
    {
        $this->authorize('viewAny', ProductUnit::class);

        $count->load(['items.variant.product:id,name', 'warehouse:id,name']);

        // The expected figure is withheld while the sheet is open AND blind. Sending it
        // and hiding it in CSS would put it one devtools panel away from the person
        // whose independence the blind mode exists to protect.
        $reveal = ! $count->is_blind || ! $count->isOpen();

        return Inertia::render('Inventory::Counts/Show', [
            'count' => [
                'id' => $count->id,
                'number' => $count->number,
                'status' => $count->status,
                'warehouse' => $count->warehouse->name,
                'warehouse_id' => $count->warehouse_id,
                'is_blind' => $count->is_blind,
                'is_open' => $count->isOpen(),
                'notes' => $count->notes,
                'applied_at' => $count->applied_at?->toIso8601String(),
                'reveals_expected' => $reveal,
                'variance' => $reveal ? $service->variance($count) : null,
            ],
            'lines' => $count->items->map(fn (StockCountItem $item): array => [
                'id' => $item->id,
                'product_name' => $item->variant->product->name,
                'variant_name' => $item->variant->displayName(),
                'counted_quantity' => $item->counted_quantity,
                'expected_quantity' => $reveal ? $item->expected_quantity : null,
                'variance' => $reveal ? $item->variance() : null,
            ])->values()->all(),
            'can' => [
                'manage' => $request->user()?->can('inventory.adjust') ?? false,
            ],
        ]);
    }

    public function storeLine(Request $request, StockCount $count, StockCountService $service): RedirectResponse
    {
        $this->authorize('adjust', ProductUnit::class);

        $validated = $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
        ]);

        if (StockCountItem::query()
            ->where('stock_count_id', $count->getKey())
            ->where('product_variant_id', $validated['product_variant_id'])
            ->exists()
        ) {
            return back()->withErrors(['line' => 'این کالا قبلاً در برگه شمارش هست.']);
        }

        try {
            $service->addLine($count, $validated['product_variant_id']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['line' => $exception->getMessage()]);
        }

        return back()->with('success', 'کالا به برگه شمارش اضافه شد.');
    }

    /**
     * Fill the whole sheet from what the warehouse is believed to hold.
     *
     * Still blind — the expected quantities are snapshotted server-side and not sent.
     * Adding fifty lines by hand is how a count never gets started.
     */
    public function fill(StockCount $count, StockCountService $service): RedirectResponse
    {
        $this->authorize('adjust', ProductUnit::class);

        if (! $count->isOpen()) {
            return back()->withErrors(['line' => 'این انبارگردانی بسته شده است.']);
        }

        $existing = StockCountItem::query()
            ->where('stock_count_id', $count->getKey())
            ->pluck('product_variant_id')
            ->all();

        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->whereNotIn('id', $existing)
            ->whereHas('product', fn ($query) => $query->where('is_active', true)->where('type', 'standard'))
            ->limit(500)
            ->get();

        foreach ($variants as $variant) {
            $service->addLine($count, $variant->id);
        }

        return back()->with('success', Digits::toPersian((string) $variants->count()).' ردیف به برگه شمارش اضافه شد.');
    }

    /**
     * Record what was counted. Uncounted lines stay null and are skipped on apply —
     * an unvisited shelf is not an empty shelf.
     */
    public function count(Request $request, StockCount $count): RedirectResponse
    {
        $this->authorize('adjust', ProductUnit::class);

        if (! $count->isOpen()) {
            return back()->withErrors(['counted' => 'این انبارگردانی بسته شده است.']);
        }

        $validated = $request->validate([
            'counted' => ['present', 'array'],
            'counted.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        foreach ($validated['counted'] as $itemId => $quantity) {
            StockCountItem::query()
                ->where('stock_count_id', $count->getKey())
                ->whereKey((int) $itemId)
                ->update(['counted_quantity' => $quantity === null ? null : (int) $quantity]);
        }

        return back()->with('success', 'شمارش ذخیره شد.');
    }

    /**
     * Write the differences as adjustment movements.
     */
    public function apply(StockCount $count, StockCountService $service): RedirectResponse
    {
        $this->authorize('adjust', ProductUnit::class);

        try {
            $written = $service->apply($count);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['apply' => $exception->getMessage()]);
        }

        return back()->with(
            'success',
            $written === 0
                ? 'انبارگردانی بسته شد. هیچ اختلافی پیدا نشد.'
                : 'انبارگردانی بسته شد و '.Digits::toPersian((string) $written).' تعدیل ثبت شد.'
        );
    }

    /**
     * A `withCount` result, typed. `getAttribute()` returns mixed, and a cast of mixed
     * is exactly what Larastan level 8 asks us not to write blind.
     */
    private function countOf(StockCount $count, string $attribute): int
    {
        /** @var int|numeric-string $value */
        $value = $count->getAttribute($attribute);

        return (int) $value;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function warehouseOptions(): array
    {
        $options = [];

        foreach (Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) as $warehouse) {
            $options[] = ['id' => $warehouse->id, 'label' => $warehouse->name];
        }

        return $options;
    }
}
