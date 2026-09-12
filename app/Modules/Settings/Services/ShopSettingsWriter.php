<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Messaging\Enums\AutomationKey;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The write half of {@see TenantShopSettings}.
 *
 * ## Why it exists
 *
 * `TenantShopSettings` has read a dozen shop preferences since Phase 1 and **nothing has
 * ever written one**. `Tenant::setting()` is a getter with a default; the single key the
 * product wrote was `setup.dismissed_at`, by hand, from a Reporting controller. Every
 * other value — the logo on a customer's receipt, the warranty wording, whether the shop
 * texts anybody — could only be changed by editing a JSON column in psql. CLAUDE.md calls
 * that a box that stays open, so this is the half that closes it.
 *
 * ## Every write is a whole section, and the reader's defaults survive it
 *
 * The reader is strict on purpose, and both of its strictnesses are load-bearing:
 *
 * · `print.show_qr` defaults **ON** — `$showQr !== false` — so that a shop which has
 *   never opened the screen keeps its QR. A write path that stored `0`, `'false'` or
 *   `null` for "off" would leave the QR on; one that omitted the key when off would do
 *   the same. So this class stores a real `bool`, always, for both directions.
 *
 * · `messaging.automations` defaults **OFF** — `$value === true`, not a truthy check —
 *   so that nothing texts a shop's customers on a guess. `'1'`, `1` and `'yes'` all read
 *   as off, which is the safe direction but would look like a screen that does not save.
 *   So this class stores a real `bool` for every case of {@see AutomationKey}, present or
 *   absent from the request.
 *
 * The automation map is rebuilt from the enum rather than merged into, which is what
 * makes that true: a key the product has since removed drops out of storage on the next
 * save instead of sitting in the document as a leftover `true`.
 *
 * ## Not metered
 *
 * No `QuotaGuard::consume()` here, deliberately (golden rule 7). A plan sells how much
 * *work* a shop may record in a month; changing a preference records no work. It also
 * has to keep working at the ceiling: a shop that has run out of credit must still be
 * able to switch its own automations off, and billing a shop for the act of spending
 * less would be an odd thing to build.
 */
final class ShopSettingsWriter
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * What the shop puts on its own paperwork.
     *
     * `$logoUrl` and `$footerTerms` take `null` for "nothing", matching the reader, which
     * treats an empty string and a whitespace-only string as absent too.
     */
    public function savePrint(?string $logoUrl, ?string $footerTerms, bool $showQr): void
    {
        $this->writeSection('print', [
            'logo_url' => $logoUrl,
            'footer_terms' => $footerTerms,
            // A real boolean in both directions. See the class docblock.
            'show_qr' => $showQr,
        ]);
    }

    /**
     * Which automatic messages are on, and the hours the shop will not text in.
     *
     * @param  list<string>  $enabled  {@see AutomationKey} values the shop ticked. Anything
     *                                 that is not a known case is ignored rather than stored.
     */
    public function saveMessaging(array $enabled, int $quietUntilHour, int $quietFromHour): void
    {
        $automations = [];

        foreach (AutomationKey::cases() as $key) {
            // `in_array(..., true)` gives a genuine bool for every case, which is the one
            // thing `MessagingSettings`'s `=== true` reader will accept as "on".
            $automations[$key->value] = in_array($key->value, $enabled, true);
        }

        $this->writeSection('messaging', [
            'automations' => $automations,
            'quiet_until_hour' => $quietUntilHour,
            'quiet_from_hour' => $quietFromHour,
        ]);
    }

    /**
     * Merge one namespace into the shop's settings document.
     *
     * ## Why the row is locked
     *
     * `tenants.settings` is one JSON column holding every section, so saving «چاپ» is a
     * read-modify-write of the whole document. Without a lock, an owner saving the print
     * screen while a manager saves the messaging screen would each write a document built
     * from their own stale copy, and whichever committed second would silently undo the
     * other — not the field they both touched, but a section they never opened.
     *
     * `tenants` is the central registry: no `tenant_id`, no RLS, no global scope (see the
     * model). So the row is addressed by key and nothing here needs the tenancy escape
     * hatch.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeSection(string $namespace, array $values): void
    {
        $tenant = $this->context->tenant();

        if ($tenant === null) {
            // Unreachable from a tenant route — `ResolveTenant` has already run — and
            // deliberately loud rather than a silent no-op, which is how a "why did my
            // settings not save" ticket is born.
            throw new RuntimeException('Shop settings were written with no tenant context.');
        }

        DB::transaction(function () use ($tenant, $namespace, $values): void {
            $fresh = $tenant->newQuery()->whereKey($tenant->getKey())->lockForUpdate()->first();

            $settings = $fresh === null ? $tenant->settings : $fresh->settings;

            $section = $settings[$namespace] ?? null;

            $settings[$namespace] = array_replace(
                is_array($section) ? $section : [],
                $values,
            );

            // Written back onto the context's own instance, not onto `$fresh`: everything
            // later in this request — a flash message, a re-render — reads the shop
            // through `TenantContext`, and updating a different copy would leave that one
            // answering with the values the screen has just replaced.
            $tenant->settings = $settings;
            $tenant->save();
        });
    }
}
