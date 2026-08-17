<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Party;
use App\Modules\Hamta\Enums\ChecklistStep;
use App\Modules\Hamta\Enums\HamtaStatus;
use App\Modules\Hamta\Http\Requests\ChecklistRequest;
use App\Modules\Hamta\Http\Requests\RecordTransferRequest;
use App\Modules\Hamta\Services\HamtaRegistry;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\BranchContext;
use App\Support\Jalali;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pending list, the checklist, and the page that explains همتا to a shop assistant.
 *
 * ## Gated on inventory permissions, not its own
 *
 * HAMTA state lives on `product_units` and is worked on by whoever handles devices. A
 * separate `hamta.*` permission would be a fourth thing to configure that always ends up
 * matching `inventory.*` anyway — and a shop that granted one and not the other would get a
 * pending list they cannot clear.
 *
 * ## The instructions page is deliberately reachable by anyone signed in
 *
 * It is a Persian explainer with no shop data on it. The person who most needs it is the
 * new assistant who has just been asked «همتا یعنی چی؟» by a customer, and making them hold
 * a stock permission to read a help page is how they end up guessing instead.
 */
final class HamtaController extends Controller
{
    public function index(Request $request, BranchContext $context): Response
    {
        $this->authorise($request, 'inventory.view');

        /*
        | Scoped to the branch being viewed, through the warehouse — `product_units` has no
        | `branch_id` of its own, it has a warehouse, and a warehouse belongs to a branch.
        | Without this the Vanak assistant would be handed the main shop's outstanding
        | transfers to chase.
        */
        $query = ProductUnit::query()
            ->with(['variant.product:id,name', 'warehouse:id,name,branch_id'])
            ->where('hamta_status', HamtaStatus::Pending->value)
            ->join('warehouses', 'warehouses.id', '=', 'product_units.warehouse_id')
            ->select('product_units.*')
            ->orderBy('product_units.acquired_at')
            ->orderBy('product_units.id');

        $units = $context->apply($query, 'warehouses.branch_id')->limit(200)->get();

        /*
        | Party names in one query rather than a relation on `ProductUnit`.
        |
        | `acquired_from_party_id` is a bare column — the FK was added in Phase 4 without a
        | relation, because Inventory does not depend on CRM. Adding one from this module
        | would put a CRM relation on an Inventory model, which is the boundary the column
        | was left bare to respect. One keyed lookup is the honest cost of that.
        */
        $partyNames = Party::query()
            ->whereKey($units->pluck('acquired_from_party_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return Inertia::render('Hamta::Hamta/Pending', [
            'units' => $units->map(fn (ProductUnit $unit): array => [
                'id' => $unit->getKey(),
                'imei' => $unit->imei1 ?? $unit->serial ?? '—',
                'product' => $this->productName($unit),
                'condition' => $unit->condition->labelFa(),
                'status' => $unit->status->value,
                'party' => $partyNames[$unit->acquired_from_party_id] ?? null,
                'warehouse' => $unit->warehouse?->name,
                'acquired_at' => Jalali::format($unit->acquired_at),
                'url' => route('inventory.units.show', $unit, absolute: false),
            ])->values()->all(),
        ]);
    }

    /**
     * The guided checklist for one device.
     */
    public function show(Request $request, ProductUnit $unit, HamtaRegistry $registry): Response
    {
        $this->authorise($request, 'inventory.view');

        return Inertia::render('Hamta::Hamta/Checklist', [
            'unit' => $this->unitPayload($unit),
            'steps' => $this->steps($registry, $unit),
            'can_manage' => $request->user() instanceof User && $request->user()->can('inventory.adjust'),
        ]);
    }

    public function checklist(ChecklistRequest $request, ProductUnit $unit, HamtaRegistry $registry): RedirectResponse
    {
        $this->authorise($request, 'inventory.adjust');

        /** @var array<string, array{answer: string, note?: string|null}> $answers */
        $answers = $request->validated('answers') ?? [];

        $registry->answerChecklist($unit, $answers, $this->actorId($request));

        return back()->with('success', 'پاسخ‌های چک‌لیست ثبت شد.');
    }

    public function record(RecordTransferRequest $request, ProductUnit $unit, HamtaRegistry $registry): RedirectResponse
    {
        $this->authorise($request, 'inventory.adjust');

        $actorId = $this->actorId($request);

        $activationId = $request->validated('activation_id');
        $note = $request->validated('note');

        if ($request->boolean('reopen')) {
            $registry->reopen($unit, is_string($note) ? $note : null, $actorId);

            return back()->with('success', 'انتقال دوباره باز شد.');
        }

        $registry->recordTransfer(
            $unit,
            is_string($activationId) ? $activationId : null,
            is_string($note) ? $note : null,
            $actorId,
        );

        return back()->with('success', 'انتقال مالکیت ثبت شد — این ثبت است، نه استعلام از همتا.');
    }

    /**
     * The plain-Persian explainer. No shop data, so no stock permission.
     */
    public function guide(): Response
    {
        return Inertia::render('Hamta::Hamta/Guide');
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(ProductUnit $unit): array
    {
        return [
            'id' => $unit->getKey(),
            'imei' => $unit->imei1 ?? $unit->serial ?? '—',
            'product' => $this->productName($unit),
            'condition' => $unit->condition->labelFa(),
            'hamta_status' => $unit->hamta_status,
            'hamta_status_label' => (HamtaStatus::tryFrom($unit->hamta_status) ?? HamtaStatus::NotRequired)->labelFa(),
            'activation_id' => $unit->hamta_activation_id,
            'transferred_at' => Jalali::format($unit->hamta_transferred_at),
            'note' => $unit->hamta_note,
            'url' => route('inventory.units.show', $unit, absolute: false),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function steps(HamtaRegistry $registry, ProductUnit $unit): array
    {
        $latest = $registry->latestAnswers($unit);
        $steps = [];

        foreach (ChecklistStep::ordered() as $step) {
            $answer = $latest[$step->value] ?? null;

            $steps[] = [
                'key' => $step->value,
                'label' => $step->labelFa(),
                'hint' => $step->hintFa(),
                'answer' => $answer?->answer,
                'note' => $answer?->note,
                'answered_at' => $answer === null ? null : Jalali::format($answer->answered_at),
                'actor' => $answer?->actor?->name,
            ];
        }

        return $steps;
    }

    private function productName(ProductUnit $unit): string
    {
        $name = $unit->variant?->product?->name;

        return is_string($name) && $name !== '' ? $name : 'بدون عنوان';
    }

    private function actorId(Request $request): ?int
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        /** @var int|numeric-string $id */
        $id = $user->getKey();

        return (int) $id;
    }

    private function authorise(Request $request, string $permission): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can($permission), 403);
    }
}
