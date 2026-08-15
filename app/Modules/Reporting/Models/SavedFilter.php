<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's named filter set for one report.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $report_key
 * @property string $name
 * @property array<string, string> $filters
 */
final class SavedFilter extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'report_key', 'name', 'filters'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }
}
