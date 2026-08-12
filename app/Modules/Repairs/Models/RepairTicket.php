<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Models;

use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One device on the bench.
 *
 * ## `device_passcode` is hidden, and `$hidden` is the load-bearing line
 *
 * A leaked unlock code is a stolen phone. The protection is deliberately layered, and
 * this class carries two of the layers:
 *
 * 1. **`encrypted` cast** — the column holds ciphertext, so a database dump, a replica,
 *    a backup on somebody's laptop and a `select *` in a console all show nothing.
 * 2. **`$hidden`** — the attribute is stripped from `toArray()` and `toJson()`. That is
 *    what stops it reaching an Inertia prop, an API resource, an export, a queued job's
 *    serialised payload, or a log line that dumped the model. Every one of those is a
 *    real path that has leaked a secret in some codebase, and none of them is a place
 *    anybody *intended* to send a passcode.
 *
 * The other two layers live elsewhere: `RepairTicketPolicy::revealPasscode()` gates who
 * may ask, and the reveal endpoint writes an audit entry every time. Reading the code is
 * a deliberate, attributable act — never a side effect of loading a page.
 *
 * **If you add an accessor, a resource or a serialiser here, do not undo `$hidden`.**
 * `RepairTicketPasscodeTest` asserts the code appears in no JSON response, no log and no
 * export, and it exists precisely because that is easy to break by accident.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $code
 * @property int|null $party_id
 * @property int|null $sales_invoice_id
 * @property int|null $product_unit_id
 * @property string|null $device_brand
 * @property string $device_model
 * @property string|null $device_imei
 * @property string|null $device_colour
 * @property string $reported_issue
 * @property TicketStatus $status
 * @property int|null $technician_id
 * @property int $priority
 * @property CarbonImmutable|null $promised_at
 * @property int $estimate_amount
 * @property int|null $approved_amount
 * @property CarbonImmutable|null $approved_at
 * @property string|null $approved_via
 * @property int|null $approved_by
 * @property string|null $approval_note
 * @property string|null $approval_token
 * @property int|null $approval_quoted_amount
 * @property CarbonImmutable|null $approval_requested_at
 * @property CarbonImmutable|null $declined_at
 * @property int $prepaid_amount
 * @property int $warranty_days
 * @property string|null $device_passcode
 * @property array<int, string>|null $accessories
 * @property string $tracking_token
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $abandoned_at
 * @property int|null $intake_by
 * @property CarbonImmutable|null $created_at
 * @property-read Branch $branch
 * @property-read Party|null $party
 * @property-read User|null $technician
 */
final class RepairTicket extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\RepairTicketFactory> */
    use HasFactory;

    use SoftDeletes;

    /** Priorities, as an Iranian counter actually triages: normal, urgent, warranty. */
    public const PRIORITY_NORMAL = 2;

    public const PRIORITY_URGENT = 1;

    public const PRIORITY_LOW = 3;

    public const APPROVED_VIA_LINK = 'link';

    public const APPROVED_VIA_PHONE = 'phone';

    /**
     * Stripped from every array and JSON representation of this model.
     *
     * This single line is what keeps the passcode out of Inertia props, API resources,
     * `Log::info($ticket)`, queued-job payloads and Excel exports. See the class
     * docblock; there is a test for each of those paths.
     *
     * @var list<string>
     */
    protected $hidden = ['device_passcode'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'queued',
        'priority' => self::PRIORITY_NORMAL,
        'estimate_amount' => 0,
        'prepaid_amount' => 0,
        'warranty_days' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'branch_id', 'code', 'party_id', 'sales_invoice_id', 'product_unit_id',
        'device_brand', 'device_model', 'device_imei', 'device_colour',
        'reported_issue', 'status', 'technician_id', 'priority', 'promised_at',
        'estimate_amount', 'approved_amount', 'approved_at', 'approved_via',
        'approved_by', 'approval_note', 'approval_token', 'approval_quoted_amount',
        'approval_requested_at', 'declined_at', 'prepaid_amount', 'warranty_days',
        'device_passcode', 'accessories', 'tracking_token',
        'ready_at', 'delivered_at', 'abandoned_at', 'intake_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => 'integer',
            'promised_at' => 'immutable_datetime',
            'estimate_amount' => 'integer',
            'approved_amount' => 'integer',
            'approved_at' => 'immutable_datetime',
            'approval_quoted_amount' => 'integer',
            'approval_requested_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'prepaid_amount' => 'integer',
            'warranty_days' => 'integer',
            // Ciphertext at rest. A database dump, a replica or a backup shows nothing.
            'device_passcode' => 'encrypted',
            'accessories' => 'array',
            'ready_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'abandoned_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether the shop is waiting on a customer's yes right now.
     */
    public function isAwaitingApproval(): bool
    {
        return $this->approval_token !== null && $this->approved_at === null;
    }

    /**
     * Whether a passcode was recorded at all.
     *
     * The UI needs to distinguish "there is a code, ask if you need it" from "the
     * customer gave us none" — and it must do so **without** reading the code. Anything
     * that answers this question by touching `device_passcode` in a prop is the leak
     * this method exists to prevent.
     */
    public function hasPasscode(): bool
    {
        return $this->getRawOriginal('device_passcode') !== null;
    }

    public function isClosed(): bool
    {
        return $this->status->isClosed();
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * The invoice that settled this repair, if it has been delivered.
     *
     * Null for everything before delivery, and for devices handed back unbilled. It is
     * how the tracking page knows whether "what you owe" is still a guess or a fact —
     * see {@see \App\Modules\Repairs\Http\Controllers\PublicTrackingController}.
     */
    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    /**
     * The handset this shop sold, when it is one.
     *
     * @return BelongsTo<ProductUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * @return HasMany<TicketStatusHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(TicketStatusHistory::class);
    }

    /**
     * @return HasMany<TicketPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(TicketPart::class);
    }

    /**
     * @return HasMany<TicketChecklistAnswer, $this>
     */
    public function checklistAnswers(): HasMany
    {
        return $this->hasMany(TicketChecklistAnswer::class);
    }
}
