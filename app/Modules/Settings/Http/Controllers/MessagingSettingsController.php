<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\AutomationKey;
use App\Modules\Settings\Http\Requests\MessagingSettingsRequest;
use App\Modules\Settings\Services\ShopSettingsWriter;
use App\Support\Settings\ShopSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «پیامک» — which automatic messages the shop sends, and when it will not send them.
 *
 * ## Swept and event-driven are shown apart, because quiet hours only bind one of them
 *
 * `MessagingSettings::isQuietAt()` is consulted by the daily sweep and not by the event
 * listeners: a repair marked ready at 9pm still texts, because the customer is waiting for
 * exactly that message, while a birthday greeting waits for morning. A screen that listed
 * all nine switches under one quiet-hours control would be telling a shopkeeper something
 * untrue about four of them.
 *
 * ## Every switch arrives off
 *
 * `MessagingSettings` defaults every automation to off and means it — a shop must never
 * wake up to messages it did not authorise, sent on credit it did not agree to spend. So
 * this screen opens with nothing ticked for a shop that has never saved, and that is the
 * correct reading of the stored document rather than a screen that failed to load.
 */
final class MessagingSettingsController extends Controller
{
    public function edit(Request $request, ShopSettings $settings): Response
    {
        $this->authorize('view', ShopSettings::class);

        $messaging = $settings->messaging();

        $user = $request->user();

        return Inertia::render('Settings::Settings/Messaging', [
            'automations' => array_map(
                fn (AutomationKey $key): array => [
                    'key' => $key->value,
                    'label' => $key->labelFa(),
                    'enabled' => $messaging->isEnabled($key),
                    'swept' => $key->isSwept(),
                ],
                AutomationKey::cases(),
            ),
            'quiet' => [
                'until_hour' => $messaging->quietUntilHour,
                'from_hour' => $messaging->quietFromHour,
            ],
            'can_update' => $user instanceof User && $user->can('settings.update'),
        ]);
    }

    public function update(MessagingSettingsRequest $request, ShopSettingsWriter $writer): RedirectResponse
    {
        /** @var list<string> $enabled */
        $enabled = array_values(array_filter(
            $request->array('automations'),
            static fn (mixed $value): bool => is_string($value),
        ));

        $writer->saveMessaging(
            enabled: $enabled,
            quietUntilHour: $request->integer('quiet_until_hour'),
            quietFromHour: $request->integer('quiet_from_hour'),
        );

        return back()->with('success', 'تنظیمات پیامک ذخیره شد.');
    }
}
