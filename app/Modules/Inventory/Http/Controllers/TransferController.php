<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockOverview;
use App\Modules\Inventory\Services\TransferService;
use App\Support\Counters\CounterService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Moving stock between warehouses, in two steps.
 *
 * Stock leaves the source on dispatch and arrives on receipt; in between it belongs to
 * neither and cannot be sold at either end. The receipt screen therefore asks what
 * actually turned up rather than assuming — five dispatched and three received is
 * something to investigate, and it is recorded as such.
 */
final class TransferController extends Controller
{
    private const PER_PAGE = 25;

    public function index(): Response
    {
        $this->authorize('viewAny', ProductUnit::class);

        $transfers = StockTransfer::query()
            ->with(['fromWarehouse:id,name', 'toWarehouse:id,name'])
            ->withCount('items')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return Inertia::render('Inventory::Transfers/Index', [
            'transfers' => [
                'rows' => array_map(fn (StockTransfer $transfer): array => [
                    'id' => $transfer->id,
                    'number' => $transfer->number,
                    'status' => $transfer->status,
                    'from' => $transfer->fromWarehouse->name,
                    'to' => $transfer->toWarehouse->name,
                    'line_count' => $this->countOf($transfer, 'items_count'),
                    'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
                    'received_at' => $transfer->received_at?->toIso8601String(),
                ], $transfers->items()),
                'links' => $transfers->linkCollection()->toArray(),
                'total' => $transfers->total(),
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
        $this->authorize('transfer', ProductUnit::class);

        $validated = $request->validate([
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'to_warehouse_id.different' => 'مبدأ و مقصد نمی‌توانند یکی باشند.',
        ]);

        $tenantId = $context->id();

        if ($tenantId === null) {
            abort(404);
        }

        /** @var StockTransfer $transfer */
        $transfer = $connection->transaction(fn (): StockTransfer => StockTransfer::query()->create([
            'from_warehouse_id' => $validated['from_warehouse_id'],
            'to_warehouse_id' => $validated['to_warehouse_id'],
            'number' => $counters->nextFormatted($tenantId, 'stock_transfer', 'TRF'),
            'status' => StockTransfer::STATUS_DRAFT,
            'notes' => $validated['notes'] ?? null,
        ]));

        return redirect()
            ->route('inventory.transfers.show', $transfer)
            ->with('success', "حواله {$transfer->number} ساخته شد.");
    }

    public function show(Request $request, StockTransfer $transfer, StockOverview $overview): Response
    {
        $this->authorize('viewAny', ProductUnit::class);

        $transfer->load([
            'items.variant.product:id,name,type',
            'items.unit:id,imei1,serial',
            'fromWarehouse:id,name',
            'toWarehouse:id,name',
        ]);

        $figures = $overview->onHandFor(
            $transfer->items->map(fn (StockTransferItem $item) => $item->variant),
            $transfer->from_warehouse_id,
        );

        return Inertia::render('Inventory::Transfers/Show', [
            'transfer' => [
                'id' => $transfer->id,
                'number' => $transfer->number,
                'status' => $transfer->status,
                'from' => $transfer->fromWarehouse->name,
                'from_warehouse_id' => $transfer->from_warehouse_id,
                'to' => $transfer->toWarehouse->name,
                'notes' => $transfer->notes,
                'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
                'received_at' => $transfer->received_at?->toIso8601String(),
                'is_draft' => $transfer->isDraft(),
                'is_dispatched' => $transfer->isDispatched(),
            ],
            'lines' => $transfer->items->map(fn (StockTransferItem $item): array => [
                'id' => $item->id,
                'product_name' => $item->variant->product->name,
                'variant_name' => $item->variant->displayName(),
                'is_serialized' => $item->product_unit_id !== null,
                'imei' => $item->unit === null ? null : ($item->unit->imei1 ?? $item->unit->serial),
                'quantity' => $item->quantity,
                'received_quantity' => $item->received_quantity,
                // What the source warehouse holds right now, so a dispatch that would
                // overdraw is visible before it is attempted rather than as an error.
                'available' => $figures[$item->product_variant_id] ?? 0,
            ])->values()->all(),
            'can' => [
                'manage' => $request->user()?->can('inventory.transfer') ?? false,
            ],
        ]);
    }

    /**
     * Add a line. A serialized line names one handset; a standard line is a quantity.
     */
    public function storeLine(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('transfer', ProductUnit::class);

        if (! $transfer->isDraft()) {
            return back()->withErrors(['line' => 'فقط به حواله پیش‌نویس می‌توان ردیف اضافه کرد.']);
        }

        $validated = $request->validate([
            'product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        if (isset($validated['product_unit_id'])) {
            /** @var ProductUnit $unit */
            $unit = ProductUnit::query()->findOrFail($validated['product_unit_id']);

            if ($unit->warehouse_id !== $transfer->from_warehouse_id) {
                return back()->withErrors([
                    'line' => 'این دستگاه در انبار مبدأ نیست؛ حواله باید از همان انباری صادر شود که دستگاه در آن است.',
                ]);
            }

            if (StockTransferItem::query()->where('product_unit_id', $unit->getKey())
                ->whereHas('transfer', fn ($query) => $query->whereIn('status', [
                    StockTransfer::STATUS_DRAFT, StockTransfer::STATUS_DISPATCHED,
                ]))->exists()
            ) {
                return back()->withErrors(['line' => 'این دستگاه هم‌اکنون روی حواله دیگری است.']);
            }

            StockTransferItem::query()->create([
                'stock_transfer_id' => $transfer->getKey(),
                'product_variant_id' => $unit->product_variant_id,
                'product_unit_id' => $unit->getKey(),
                'quantity' => 1,
            ]);

            return back()->with('success', 'دستگاه به حواله اضافه شد.');
        }

        if (! isset($validated['product_variant_id'], $validated['quantity'])) {
            return back()->withErrors(['line' => 'یک دستگاه اسکن کنید یا کالا و تعداد را انتخاب کنید.']);
        }

        StockTransferItem::query()->create([
            'stock_transfer_id' => $transfer->getKey(),
            'product_variant_id' => $validated['product_variant_id'],
            'quantity' => $validated['quantity'],
        ]);

        return back()->with('success', 'ردیف اضافه شد.');
    }

    public function destroyLine(StockTransfer $transfer, StockTransferItem $item): RedirectResponse
    {
        $this->authorize('transfer', ProductUnit::class);

        if (! $transfer->isDraft()) {
            return back()->withErrors(['line' => 'حواله ارسال‌شده قابل ویرایش نیست.']);
        }

        $item->delete();

        return back()->with('success', 'ردیف حذف شد.');
    }

    public function dispatch(StockTransfer $transfer, TransferService $transfers): RedirectResponse
    {
        $this->authorize('transfer', ProductUnit::class);

        try {
            $transfers->dispatch($transfer);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['dispatch' => $exception->getMessage()]);
        }

        return back()->with('success', "حواله {$transfer->number} ارسال شد. کالا از انبار مبدأ خارج شد.");
    }

    /**
     * Receive what actually arrived — which is not necessarily what was sent.
     */
    public function receive(Request $request, StockTransfer $transfer, TransferService $transfers): RedirectResponse
    {
        $this->authorize('transfer', ProductUnit::class);

        $validated = $request->validate([
            'counted' => ['present', 'array'],
            'counted.*' => ['integer', 'min:0'],
        ]);

        /** @var array<int, int> $counted */
        $counted = [];

        foreach ($validated['counted'] as $itemId => $quantity) {
            $counted[(int) $itemId] = (int) $quantity;
        }

        try {
            $transfers->receive($transfer, $counted);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['receive' => $exception->getMessage()]);
        }

        return back()->with('success', "حواله {$transfer->number} تحویل گرفته شد.");
    }

    /**
     * A `withCount` result, typed — `getAttribute()` returns mixed.
     */
    private function countOf(StockTransfer $transfer, string $attribute): int
    {
        /** @var int|numeric-string $value */
        $value = $transfer->getAttribute($attribute);

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
