<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A sellable capability, matching a folder under app/Modules.
 *
 * `code` is the same string used everywhere else: the Pennant feature `module:<code>`,
 * the `module:<code>` route middleware, and the nav's `feature` key. One spelling, so
 * a typo fails loudly rather than silently leaving a module ungated.
 *
 * @property int $id
 * @property string $code
 * @property string $name_fa
 * @property bool $is_addonable
 * @property bool $is_core
 * @property bool $is_enabled
 * @property int|null $addon_price
 * @property int $position
 */
final class Module extends Model
{
    protected $fillable = [
        'code', 'name_fa', 'description_fa', 'is_addonable', 'addon_price', 'is_core', 'is_enabled', 'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_addonable' => 'boolean',
            'is_core' => 'boolean',
            'is_enabled' => 'boolean',
            'addon_price' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Plan, $this>
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_module');
    }

    public function featureName(): string
    {
        return 'module:'.$this->code;
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
