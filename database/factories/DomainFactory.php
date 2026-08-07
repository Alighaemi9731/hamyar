<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Domain>
 */
final class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'hostname' => Domain::hostnameFor('shop-'.Str::lower(Str::random(8))),
            'is_primary' => true,
        ];
    }
}
