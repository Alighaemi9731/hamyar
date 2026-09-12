<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Settings\Http\Requests\PrintSettingsRequest;
use App\Modules\Settings\Services\ShopSettingsWriter;
use App\Support\Settings\ShopSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «چاپ و هویت فروشگاه» — what the shop's own customer ends up holding.
 *
 * The values come back out of {@see ShopSettings} rather than off the settings document,
 * so the screen shows exactly what the print layout will use — defaults included. Reading
 * the raw column instead would render an empty QR checkbox for every shop that has never
 * saved, while their receipts carried the QR, and the screen would be lying about the
 * product's behaviour in the one place a shopkeeper goes to check it.
 */
final class PrintSettingsController extends Controller
{
    public function edit(Request $request, ShopSettings $settings): Response
    {
        $this->authorize('view', ShopSettings::class);

        $print = $settings->print();

        $user = $request->user();

        return Inertia::render('Settings::Settings/Print', [
            'print' => [
                'logo_url' => $print->logoUrl,
                'footer_terms' => $print->footerTerms,
                'show_qr' => $print->showQr,
            ],
            'can_update' => $user instanceof User && $user->can('settings.update'),
        ]);
    }

    public function update(PrintSettingsRequest $request, ShopSettingsWriter $writer): RedirectResponse
    {
        $writer->savePrint(
            // `string()->value()` then a null for the empty case, matching the reader's
            // own idea of "nothing": a field somebody cleared must store null rather than
            // an empty string, or `PrintSettings::$logoUrl` would be a URL of length zero
            // in the invoice snapshot.
            logoUrl: $this->nullIfBlank($request->string('logo_url')->value()),
            footerTerms: $this->nullIfBlank($request->string('footer_terms')->value()),
            showQr: $request->boolean('show_qr'),
        );

        return back()->with('success', 'تنظیمات چاپ ذخیره شد.');
    }

    private function nullIfBlank(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
