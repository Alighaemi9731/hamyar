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
 * @property int|null $addon_price
 */
final class Module extends Model
{
    protected $fillable = [
        'code', 'name_fa', 'description_fa', 'is_addonable', 'addon_price', 'is_core', 'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_addonable' => 'boolean',
            'is_core' => 'boolean',
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
}
