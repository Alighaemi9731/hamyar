<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A capability, matching a folder under app/Modules.
 *
 * **No longer a sellable one.** Until 0.15.0 a plan was a bundle: `plan_module` said which
 * modules a shop's plan opened and `subscription_addons` sold the rest one at a time. Since
 * DECISION GATE 6 (ADR 0018) every module is open to every shop and what a plan sells is
 * how much work a shop may record in a Jalali month. So this table no longer answers "may
 * this shop use Cheques" — nothing does, because the answer is always yes.
 *
 * What it still answers is "have *we* switched Cheques on at all", which is a different
 * question with a different owner: `is_enabled` is the platform kill-switch that
 * `EnsureModuleEnabled` consults, and it exists for a module with no provider behind it
 * (Moadian, ADR 0011) or one we have taken down. `is_core` records which modules a shop
 * cannot function without — nothing gates on it; it is documentation with a schema.
 *
 * `code` is the same string used everywhere else: the `module:<code>` route middleware and
 * the nav's key. One spelling, so a typo fails loudly rather than silently leaving a
 * module unreachable.
 *
 * @property int $id
 * @property string $code
 * @property string $name_fa
 * @property bool $is_core
 * @property bool $is_enabled
 * @property int $position
 */
final class Module extends Model
{
    protected $fillable = [
        'code', 'name_fa', 'description_fa', 'is_core', 'is_enabled', 'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Every module code we have switched on, platform-wide.
     *
     * Central data, identical for every shop, so the cache carries no tenant key and needs
     * none (ADR 0012 audit). Read on every request that renders the nav, which is why it
     * is cached at all — and short enough that turning a module off in the panel takes
     * effect within a minute rather than needing a deploy or a manual flush.
     *
     * @return list<string>
     */
    public static function enabledCodes(): array
    {
        /** @var list<string> $codes */
        $codes = cache()->remember('platform.modules.enabled', 60, static fn (): array => self::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->pluck('code')
            ->all());

        return $codes;
    }

    /**
     * Is this module switched on for everybody?
     *
     * The kill-switch `EnsureModuleEnabled` consults. It answers "have we turned this on",
     * never "did this shop buy it" — since DECISION GATE 6 no plan buys a module.
     */
    public static function isEnabledPlatformWide(string $code): bool
    {
        return in_array($code, self::enabledCodes(), true);
    }
}
