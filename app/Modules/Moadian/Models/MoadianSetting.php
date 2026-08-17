<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One shop's Moadian credentials and switch.
 *
 * ## `private_key` is encrypted and hidden, and both matter
 *
 * `encrypted` keeps it out of a database dump, a replica and a backup. `$hidden` keeps it
 * out of `toArray()`, which is what an Inertia prop, an API resource and a tenant export
 * all call — and the spec makes "never appears in a log, response or export" an acceptance
 * criterion rather than a nicety.
 *
 * The pairing is the lesson from the repair passcode: four layers protected the value after
 * it reached the model, and the leak was on a path that never got that far. Here the
 * settings form is the equivalent risk, so the controller never echoes the key back —
 * a blank field means "unchanged", not "empty".
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $memory_id
 * @property string|null $economic_code
 * @property string $provider
 * @property string|null $private_key
 * @property bool $is_enabled
 */
final class MoadianSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'memory_id', 'economic_code', 'provider', 'private_key', 'is_enabled',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['private_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'is_enabled' => 'boolean',
        ];
    }
}
