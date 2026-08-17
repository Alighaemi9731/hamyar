<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Moadian\Jobs\SubmitInvoiceJob;
use App\Modules\Moadian\Models\MoadianInvoice;
use App\Modules\Moadian\Models\MoadianSetting;
use App\Modules\Moadian\Services\SubmitInvoice;
use App\Support\Jalali;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The submission list, the error inbox, and the resend button.
 *
 * ## The inbox is the module's whole reason for shipping now
 *
 * The spec's sentence: *silent failures are the worst possible outcome — the shop finds out
 * at audit time.* A rejection has to land somewhere a shop owner looks, in Persian, with the
 * invoice one click away. That screen is what makes the difference between "the tax module
 * is broken" and "invoice ۱۴۰۵-۰۰۴۲ was refused because the buyer's economic code is wrong".
 *
 * ## It says «به‌زودی» while the flag is off
 *
 * Which at launch is every shop ([ADR 0011](../../../../docs/adr/0011-moadian-adapter-without-a-provider.md)).
 * The screen renders and explains its own state rather than 404ing: a shop that bought a
 * plan mentioning سامانه مودیان should find a page that tells them where it stands, not a
 * missing route.
 */
final class MoadianController extends Controller
{
    public function index(Request $request, SubmitInvoice $submitter): Response
    {
        $this->authorise($request);

        $settings = MoadianSetting::query()->first();

        $submissions = MoadianInvoice::query()
            ->with('invoice:id,number,issued_at,total')
            ->when(
                $request->string('status')->value() !== '',
                fn ($query) => $query->where('status', $request->string('status')->value()),
            )
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Moadian::Moadian/Index', [
            'enabled' => $submitter->isEnabled($settings),
            // Two switches, reported apart: a shop that turned theirs on still submits
            // nothing while the deployment flag is off, and «چرا کار نمی‌کند؟» needs an
            // answer that distinguishes the two.
            'platform_enabled' => config()->boolean('moadian.enabled', false),
            'shop_enabled' => $settings instanceof MoadianSetting && $settings->is_enabled,
            'provider' => $settings instanceof MoadianSetting ? $settings->provider : 'fake',
            'status' => $request->string('status')->value() ?: null,
            'counts' => [
                'pending' => MoadianInvoice::query()->where('status', MoadianInvoice::STATUS_PENDING)->count(),
                'accepted' => MoadianInvoice::query()->where('status', MoadianInvoice::STATUS_ACCEPTED)->count(),
                'rejected' => MoadianInvoice::query()->where('status', MoadianInvoice::STATUS_REJECTED)->count(),
                'failed' => MoadianInvoice::query()->where('status', MoadianInvoice::STATUS_FAILED)->count(),
            ],
            'submissions' => [
                'data' => array_map($this->row(...), $submissions->items()),
                'links' => $submissions->linkCollection()->toArray(),
                'total' => $submissions->total(),
            ],
            'can_manage' => $request->user() instanceof User && $request->user()->can('settings.update'),
        ]);
    }

    /**
     * Send it again — after the shop fixed whatever was rejected.
     *
     * Idempotent by construction: `retry()` resets the existing row rather than creating a
     * second one, and the partial unique index would refuse a duplicate anyway. An already
     * accepted submission is left alone rather than filed twice.
     */
    public function resend(Request $request, MoadianInvoice $submission, SubmitInvoice $submitter): RedirectResponse
    {
        $this->authorise($request, 'settings.update');

        if ($submission->status === MoadianInvoice::STATUS_ACCEPTED) {
            return back()->with('info', 'این سند قبلاً پذیرفته شده است.');
        }

        $submitter->retry($submission);

        SubmitInvoiceJob::dispatch(idOfModel($submission));

        return back()->with('success', 'سند دوباره در صف ارسال قرار گرفت.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MoadianInvoice $submission): array
    {
        return [
            'id' => $submission->getKey(),
            'type' => $submission->type,
            'status' => $submission->status,
            'invoice_number' => $submission->invoice?->number,
            'invoice_url' => $submission->invoice === null
                ? null
                : route('sales.invoices.show', $submission->invoice, absolute: false),
            'reference' => $submission->reference_number,
            'tax_id' => $submission->tax_id,
            'error_code' => $submission->error_code,
            'error_message' => $submission->error_message,
            'attempts' => $submission->attempts,
            'sent_at' => Jalali::format($submission->sent_at),
        ];
    }

    private function authorise(Request $request, string $permission = 'settings.view'): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can($permission), 403);
    }
}
