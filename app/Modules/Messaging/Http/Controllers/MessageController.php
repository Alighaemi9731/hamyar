<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Enums\AutomationKey;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\SmsWallet;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The message log — what went out, what did not, and why not.
 *
 * ## Suppressed messages are shown, not hidden
 *
 * A row that never left the building is the most useful row on this screen. «چرا برای این
 * مشتری پیامک نرفت؟» is the question a shopkeeper actually asks, and the answer — opted
 * out, no credit, bad number — is only available if the attempt was recorded. A log that
 * only listed successes would make every failure a silence somebody has to guess at.
 *
 * ## The wallet sits at the top
 *
 * Because the commonest reason messages stop going out is an empty one, and a shop
 * discovering that from a customer complaint is a shop that stopped trusting the feature.
 */
final class MessageController extends Controller
{
    public function index(Request $request, SmsWallet $wallet): Response
    {
        $this->authorize('viewAny', Message::class);

        $status = $request->string('status')->value();

        $messages = Message::query()
            ->with('party:id,name')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return Inertia::render('Messaging::Messaging/Index', [
            'balance' => Money::toArray($wallet->balance()),
            'status' => $status,
            'counts' => [
                'sent' => Message::query()->where('status', Message::STATUS_SENT)->count(),
                'suppressed' => Message::query()->where('status', Message::STATUS_SUPPRESSED)->count(),
                'failed' => Message::query()->where('status', Message::STATUS_FAILED)->count(),
            ],
            'messages' => [
                'data' => collect($messages->items())->map(fn (Message $message): array => [
                    'id' => $message->id,
                    'to' => $message->to,
                    'party_name' => $message->party?->name,
                    'template_key' => $message->template_key,
                    'template_label' => $this->labelFor($message->template_key),
                    'status' => $message->status,
                    'error' => $message->error,
                    'cost' => Money::toArray($message->cost),
                    'queued_at' => $message->queued_at->toIso8601String(),
                    'sent_at' => $message->sent_at?->toIso8601String(),
                ])->values()->all(),
                'links' => $messages->linkCollection()->toArray(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /**
     * A Persian name for an automation key, or the raw key for campaigns and ad-hoc sends.
     */
    private function labelFor(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        return AutomationKey::tryFrom($key)?->labelFa() ?? $key;
    }
}
