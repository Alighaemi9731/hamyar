<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hostname that resolves to a tenant.
 *
 * Central model — the lookup has to work *before* a tenant context exists, so it
 * cannot itself be tenant-scoped.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $hostname
 * @property bool $is_primary
 */
final class Domain extends Model
{
    /** @use HasFactory<\Database\Factories\DomainFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'hostname',
        'is_primary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Build the hostname for a subdomain on the platform's bare domain.
     */
    public static function hostnameFor(string $subdomain): string
    {
        return $subdomain.'.'.config()->string('app.domain');
    }
}
