<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\BranchAccess;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Exceptions\IllegalTicketTransition;
use App\Modules\Repairs\Http\Requests\TicketDeliveryRequest;
use App\Modules\Repairs\Http\Requests\TicketIntakeRequest;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Models\TicketPart;
use App\Modules\Repairs\Models\TicketStatusHistory;
use App\Modules\Repairs\Services\DeliverTicket;
use App\Modules\Repairs\Services\TicketIntake;
use App\Modules\Repairs\Services\TicketParts;
use App\Modules\Repairs\Services\TicketStateMachine;
use App\Modules\Repairs\Services\TrackingLink;
use App\Modules\Sales\Services\PaymentOptions;
use App\Support\Files\AttachmentStore;
use App\Support\Money;
use App\Support\QrRenderer;
use App\Support\Settings\ShopSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The bench, as screens.
 *
 * ## The ticket page never carries the passcode
 *
 * `has_passcode` is a boolean and that is all this controller will say. The value lives
 * behind {@see PasscodeController}, which audits every read. A masked field in a prop is
 * still a value in the page source, in browser memory and in any screenshot — masking is
 * a UI affordance, not a protection.
 */
final class TicketController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * Cards rendered per board column.
     *
     * A shop with three hundred queued tickets has a problem no amount of DOM will fix,
     * and rendering them all makes the board unusable for the shops that do not. The
     * count above each column is the true total, and the column says when it is showing
     * fewer — a board that silently truncates lies about the size of the queue.
     */
    private const BOARD_COLUMN_LIMIT = 50;

    public function __construct(
        private readonly BranchAccess $branches,
        private readonly ShopSettings $settings,
        // Payment is a Sales concept; the delivery screen consumes it as a public
        // service rather than growing its own copy (ADR 0003).
        private readonly PaymentOptions $options,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RepairTicket::class);

        /** @var User $user */
        $user = $request->user();

        $term = trim($request->string('q')->value());

        $tickets = RepairTicket::query()
            ->with(['party:id,name', 'technician:id,name', 'branch:id,name'])
            ->tap(fn ($query) => $this->branches->constrain($query, $user))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->when($request->boolean('mine'), fn ($query) => $query->where('technician_id', $user->id))
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $q->where('code', 'ilike', "%{$term}%")
                    // The counter usually has the phone, not the paper.
                    ->orWhere('device_imei', 'ilike', "%{$term}%")
                    ->orWhere('device_model', 'ilike', "%{$term}%")
                    ->orWhereHas('party', fn ($p) => $p->where('name', 'ilike', "%{$term}%"));
            }))
            // Urgent first, then oldest — a queue that hides the device that has been
            // waiting longest is a queue that grows a rusty tail.
            ->orderBy('priority')
            ->orderBy('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Repairs::Tickets/Index', [
            'tickets' => [
                'rows' => array_map(fn (RepairTicket $ticket): array => $this->row($ticket), $tickets->items()),
                'links' => $tickets->linkCollection()->toArray(),
                'total' => $tickets->total(),
            ],
            'filters' => [
                'q' => $term,
                'status' => $request->string('status')->value() ?: null,
                'mine' => $request->boolean('mine'),
            ],
            'statuses' => $this->statusOptions(),
            'columns' => array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::boardColumns()),
            'can' => ['create' => $user->can('repairs.create')],
        ]);
    }

    /**
     * The board.
     *
     * A column per bench state, `delivered` and `rejected` deliberately absent — a board
     * is a picture of work in the shop, and a column that only grows is one nobody reads.
     *
     * Capped per column and told when it capped. A shop with three hundred queued
     * tickets has a different problem than a rendering one, and a board that silently
     * shows the first fifty is a board that lies about the size of the queue.
     */
    public function board(Request $request): Response
    {
        $this->authorize('viewAny', RepairTicket::class);

        /** @var User $user */
        $user = $request->user();

        $columns = TicketStatus::boardColumns();

        $tickets = RepairTicket::query()
            ->with(['party:id,name', 'technician:id,name'])
            ->whereIn('status', array_map(fn (TicketStatus $s): string => $s->value, $columns))
            ->tap(fn ($query) => $this->branches->constrain($query, $user))
            ->when($request->boolean('mine'), fn ($query) => $query->where('technician_id', $user->id))
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();

        $counts = [];
        $grouped = [];

        foreach ($columns as $column) {
            $inColumn = $tickets->where('status', $column)->values();

            $counts[$column->value] = $inColumn->count();
            $grouped[$column->value] = $inColumn
                ->take(self::BOARD_COLUMN_LIMIT)
                ->map(fn (RepairTicket $ticket): array => $this->row($ticket))
                ->values()
                ->all();
        }

        return Inertia::render('Repairs::Tickets/Board', [
            'columns' => array_map(fn (TicketStatus $s): array => [
                'value' => $s->value,
                'label' => $s->labelFa(),
                // The board only ever offers moves the machine will accept, so a card
                // cannot be dragged somewhere that springs it back.
                'allows' => array_map(
                    fn (TicketStatus $to): string => $to->value,
                    $s->allowedTransitions(),
                ),
            ], $columns),
            'tickets' => $grouped,
            'counts' => $counts,
            'limit' => self::BOARD_COLUMN_LIMIT,
            'filters' => ['mine' => $request->boolean('mine')],
            'can' => [
                'create' => $user->can('repairs.create'),
                'update' => $user->can('repairs.update'),
            ],
        ]);
    }

    /**
     * Who is carrying what.
     *
     * Open work only — `ready` is finished from the bench's point of view, and counting
     * it against a technician's load would make somebody with ten collected-tomorrow
     * devices look busier than somebody with two broken ones.
     */
    public function workload(Request $request): Response
    {
        $this->authorize('viewAny', RepairTicket::class);

        /** @var User $user */
        $user = $request->user();

        $open = array_map(
            fn (TicketStatus $s): string => $s->value,
            array_values(array_filter(TicketStatus::cases(), fn (TicketStatus $s): bool => $s->isOpenWork())),
        );

        $tickets = RepairTicket::query()
            ->with('technician:id,name')
            ->whereIn('status', $open)
            ->tap(fn ($query) => $this->branches->constrain($query, $user))
            ->get();

        $rows = [];

        foreach ($tickets as $ticket) {
            $key = $ticket->technician_id ?? 0;

            $technician = $ticket->technician;

            $rows[$key] ??= [
                'id' => $ticket->technician_id,
                // Unassigned is a row, not an omission: it is the pile nobody owns, and
                // it is the first thing a manager should be looking at.
                'name' => $technician === null ? 'تخصیص‌نیافته' : $technician->name,
                'open' => 0,
                'urgent' => 0,
                'overdue' => 0,
            ];

            $rows[$key]['open']++;

            if ($ticket->priority === RepairTicket::PRIORITY_URGENT) {
                $rows[$key]['urgent']++;
            }

            if ($ticket->promised_at !== null && $ticket->promised_at->isPast()) {
                $rows[$key]['overdue']++;
            }
        }

        // Unassigned first, then the heaviest load.
        uasort($rows, function (array $a, array $b): int {
            if ($a['id'] === null) {
                return -1;
            }

            if ($b['id'] === null) {
                return 1;
            }

            return $b['open'] <=> $a['open'];
        });

        return Inertia::render('Repairs::Tickets/Workload', [
            'rows' => array_values($rows),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', RepairTicket::class);

        /** @var User $user */
        $user = $request->user();

        $branch = $this->branches->defaultFor($user);

        abort_if($branch === null, 409, 'این کاربر به هیچ شعبه‌ای دسترسی ندارد.');

        return Inertia::render('Repairs::Tickets/Intake', [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'technicians' => $this->technicianOptions(),
            'checklist' => $this->checklistTemplate(),
            'accessories' => $this->accessoryOptions(),
            'approval_cap' => Money::toArray($this->settings->repairs()->approvalCap),
        ]);
    }

    public function store(TicketIntakeRequest $request, TicketIntake $intake, AttachmentStore $files): RedirectResponse
    {
        $this->authorize('create', RepairTicket::class);

        /** @var User $user */
        $user = $request->user();

        if (! $this->branches->canUse($user, $request->integer('branch_id'))) {
            throw ValidationException::withMessages(['branch_id' => 'شما به این شعبه دسترسی ندارید.']);
        }

        try {
            $ticket = $intake->take($request->intake(), $user->id);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['device_model' => $exception->getMessage()]);
        }

        // Photos after the ticket exists, and outside its transaction: a slow upload to
        // MinIO must not hold a database transaction open, and a photo that fails to
        // store is worth a warning rather than losing the intake the customer just
        // watched somebody type.
        foreach ($request->file('photos') ?? [] as $photo) {
            if ($photo instanceof UploadedFile) {
                $files->attach($ticket, $photo, 'intake_photos', $user->id);
            }
        }

        return redirect()
            ->route('repairs.tickets.receipt', $ticket)
            ->with('success', "قبض پذیرش {$ticket->code} ثبت شد.");
    }

    public function show(Request $request, RepairTicket $ticket): Response
    {
        $this->authorize('view', $ticket);

        $ticket->load([
            'party:id,name',
            'technician:id,name',
            'branch:id,name',
            'checklistAnswers',
            'histories.actor:id,name',
            'parts.variant.product:id,name',
        ]);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Repairs::Tickets/Show', [
            'ticket' => [
                ...$this->row($ticket),
                'reported_issue' => $ticket->reported_issue,
                'device_brand' => $ticket->device_brand,
                'device_colour' => $ticket->device_colour,
                'accessories' => $ticket->accessories ?? [],
                'estimate_amount' => Money::toArray($ticket->estimate_amount),
                'approved_amount' => $ticket->approved_amount === null ? null : Money::toArray($ticket->approved_amount),
                'approved_at' => $ticket->approved_at?->toIso8601String(),
                'approved_via' => $ticket->approved_via,
                'prepaid_amount' => Money::toArray($ticket->prepaid_amount),
                'warranty_days' => $ticket->warranty_days,
                /*
                | A boolean, never the value. The UI needs to know whether to offer the
                | reveal button; it does not need — and must never hold — the code.
                */
                'has_passcode' => $ticket->hasPasscode(),
                'checklist' => $ticket->checklistAnswers->map(fn ($a): array => [
                    'label' => $a->label,
                    'answer' => $a->answer,
                    'note' => $a->note,
                ])->values()->all(),
                'parts' => $ticket->parts->map(function (TicketPart $p): array {
                    $variant = $p->variant;
                    $productName = $variant?->product->name;
                    $variantName = $variant?->displayName();

                    return [
                        'id' => $p->id,
                        'name' => $productName ?? 'قطعه',
                        // Suppressed when it would only repeat the product name — see
                        // {@see \App\Modules\Repairs\Services\PartLookup}.
                        'variant_name' => $variantName === $productName ? null : $variantName,
                        'quantity' => $p->quantity,
                        'state' => $p->state,
                        'unit_price' => Money::toArray($p->unit_price),
                    ];
                })->values()->all(),
                'history' => $ticket->histories->map(fn (TicketStatusHistory $h): array => [
                    'id' => $h->id,
                    'from' => $h->from_status?->labelFa(),
                    'to' => $h->to_status->labelFa(),
                    'actor' => $h->actor?->name,
                    'note' => $h->note,
                    'at' => $h->created_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'transitions' => array_map(fn (TicketStatus $s): array => [
                'value' => $s->value,
                'label' => $s->labelFa(),
            ], $ticket->status->allowedTransitions()),
            'can' => [
                'update' => $user->can('repairs.update'),
                'reveal_passcode' => $user->can('repairs.reveal_passcode'),
                'deliver' => $user->can('repairs.deliver'),
            ],
        ]);
    }

    /**
     * قبض پذیرش — the paper the customer walks out with.
     *
     * 80mm thermal, because that is the printer on an Iranian shop counter. The tracking
     * QR is given real estate rather than tucked in a corner: it is the whole reason the
     * shop will not get a phone call on Thursday asking whether the phone is ready.
     */
    public function receipt(Request $request, RepairTicket $ticket, TrackingLink $links, QrRenderer $qr): Response
    {
        $this->authorize('view', $ticket);

        $ticket->load(['party:id,name', 'branch:id,name,address,phone', 'checklistAnswers']);

        $tracking = $links->for($ticket);

        return Inertia::render('Repairs::Tickets/Receipt', [
            'ticket' => [
                'code' => $ticket->code,
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'device_colour' => $ticket->device_colour,
                'device_imei' => $ticket->device_imei,
                'reported_issue' => $ticket->reported_issue,
                'party_name' => $ticket->party?->name,
                'accessories' => $ticket->accessories ?? [],
                'estimate_amount' => Money::toArray($ticket->estimate_amount),
                'prepaid_amount' => Money::toArray($ticket->prepaid_amount),
                'promised_at' => $ticket->promised_at?->toIso8601String(),
                'created_at' => $ticket->created_at?->toIso8601String(),
                'checklist' => $ticket->checklistAnswers->map(fn ($a): array => [
                    'label' => $a->label,
                    'answer' => $a->answer,
                ])->values()->all(),
            ],
            'shop' => [
                'name' => $ticket->branch->name,
                'address' => $ticket->branch->address,
                'phone' => $ticket->branch->phone,
            ],
            'tracking' => [
                'url' => $tracking,
                // Server-rendered, like the invoice QR: what a camera reads has to be
                // exactly what we meant to encode.
                'qr_svg' => $tracking === null ? null : $qr->svg($tracking),
            ],
        ]);
    }

    /**
     * The delivery screen.
     */
    public function deliveryForm(Request $request, RepairTicket $ticket, TicketParts $parts): Response
    {
        $this->authorize('deliver', $ticket);

        if ($ticket->status->isClosed()) {
            abort(404);
        }

        $ticket->load(['party:id,name', 'parts.variant.product:id,name']);

        return Inertia::render('Repairs::Tickets/Deliver', [
            'ticket' => [
                'id' => $ticket->id,
                'code' => $ticket->code,
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'party_name' => $ticket->party?->name,
                'status' => $ticket->status->value,
                'can_deliver' => $ticket->status->canTransitionTo(TicketStatus::Delivered),
                'prepaid_amount' => Money::toArray($ticket->prepaid_amount),
                'approved_amount' => $ticket->approved_amount === null
                    ? null
                    : Money::toArray($ticket->approved_amount),
                'estimate_amount' => Money::toArray($ticket->estimate_amount),
                'parts' => $ticket->parts
                    ->where('state', 'consumed')
                    ->map(fn ($part): array => [
                        'name' => $part->variant?->product->name ?? 'قطعه',
                        'quantity' => $part->quantity,
                        'unit_price' => Money::toArray($part->unit_price),
                    ])->values()->all(),
            ],
            'accounts' => $this->options->accounts(),
            'payment_methods' => $this->options->methods(),
        ]);
    }

    public function deliver(
        TicketDeliveryRequest $request,
        RepairTicket $ticket,
        DeliverTicket $delivery,
        AttachmentStore $files,
    ): RedirectResponse {
        $this->authorize('deliver', $ticket);

        /** @var User $user */
        $user = $request->user();

        try {
            $invoice = $delivery->deliver(
                $ticket,
                $request->labour(),
                $request->payments(),
                $request->integer('warranty_days'),
                $user->id,
            );
        } catch (RuntimeException $exception) {
            // On the general region, not a field — the failures here are about the
            // ticket's state and the payment total, neither of which belongs beside an
            // input (CLAUDE.md convention).
            throw ValidationException::withMessages(['delivery' => $exception->getMessage()]);
        }

        // After the sale, and outside its transaction: a slow upload must not hold a
        // database transaction open.
        //
        // And it CANNOT be allowed to fail the request. By this line the device has been
        // handed over, the invoice is numbered and the money is posted — all committed.
        // Letting a storage error surface as a 500 tells the operator the delivery failed
        // when it did not, so they press the button again and meet a refusal about a
        // ticket that is already delivered. A missing signature image is worth a warning;
        // it is not worth making somebody think they lost the sale.
        //
        // Found by walking this in a browser: the S3 adapter was not installed, the
        // upload threw, and a completed delivery rendered as an error page.
        $signature = $request->file('signature');
        $signatureFailed = false;

        if ($signature instanceof UploadedFile) {
            try {
                $files->attach($ticket, $signature, 'signature', $user->id);
            } catch (Throwable $exception) {
                report($exception);
                $signatureFailed = true;
            }
        }

        return redirect()
            ->route('sales.invoices.show', $invoice)
            ->with(
                $signatureFailed ? 'warning' : 'success',
                $signatureFailed
                    ? "دستگاه تیکت {$ticket->code} تحویل شد، اما امضا ذخیره نشد."
                    : "دستگاه تیکت {$ticket->code} تحویل شد.",
            );
    }

    public function transition(Request $request, RepairTicket $ticket, TicketStateMachine $machine): RedirectResponse
    {
        $this->authorize('update', $ticket);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $to = TicketStatus::tryFrom($validated['status']);

        if (! $to instanceof TicketStatus) {
            throw ValidationException::withMessages(['status' => 'وضعیت نامعتبر است.']);
        }

        try {
            $machine->transition($ticket, $to, $user->id, $validated['note'] ?? null);
        } catch (IllegalTicketTransition|RuntimeException $exception) {
            // Surfaced on the field the board reads, so a card that springs back says why.
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        return back()->with('success', "وضعیت تیکت {$ticket->code} به «{$to->labelFa()}» تغییر کرد.");
    }

    /**
     * @return array<string, mixed>
     */
    private function row(RepairTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'code' => $ticket->code,
            'status' => $ticket->status->value,
            'status_label' => $ticket->status->labelFa(),
            'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
            'device_imei' => $ticket->device_imei,
            'party_name' => $ticket->party?->name,
            'technician_name' => $ticket->technician?->name,
            'branch_name' => $ticket->branch->name,
            'priority' => $ticket->priority,
            'promised_at' => $ticket->promised_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'ready_at' => $ticket->ready_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(fn (TicketStatus $s): array => [
            'value' => $s->value,
            'label' => $s->labelFa(),
        ], TicketStatus::cases());
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function technicianOptions(): array
    {
        $users = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();

        return array_values(array_map(
            fn (User $technician): array => ['id' => $technician->id, 'name' => $technician->name],
            $users,
        ));
    }

    /**
     * The intake checklist.
     *
     * A built-in default for now; the per-tenant template builder is a Settings screen
     * later in this phase. Shipping the default first means a shop that never opens that
     * screen still records the condition of the device — which is the thing that settles
     * «صفحه از قبل شکسته بود» three weeks later.
     *
     * @return list<array{item_key: string, label: string, options: list<string>}>
     */
    private function checklistTemplate(): array
    {
        $condition = ['سالم', 'خط و خش', 'شکسته', 'کار نمی‌کند'];

        return [
            ['item_key' => 'screen', 'label' => 'صفحه نمایش', 'options' => $condition],
            ['item_key' => 'body', 'label' => 'بدنه و قاب', 'options' => $condition],
            ['item_key' => 'camera', 'label' => 'دوربین', 'options' => $condition],
            ['item_key' => 'battery', 'label' => 'باتری', 'options' => $condition],
            ['item_key' => 'charge_port', 'label' => 'سوکت شارژ', 'options' => $condition],
            ['item_key' => 'buttons', 'label' => 'دکمه‌ها', 'options' => $condition],
            ['item_key' => 'water', 'label' => 'نشانه تماس با آب', 'options' => ['ندارد', 'دارد', 'مشخص نیست']],
            ['item_key' => 'powers_on', 'label' => 'روشن می‌شود', 'options' => ['بله', 'خیر']],
        ];
    }

    /**
     * @return list<string>
     */
    private function accessoryOptions(): array
    {
        return ['قاب', 'سیم‌کارت', 'خشاب سیم‌کارت', 'کارت حافظه', 'شارژر', 'کابل', 'هندزفری', 'جعبه'];
    }
}
