<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Random rather than sequential so a test that accidentally depends on slug
        // ordering fails loudly instead of passing by luck.
        $slug = 'shop-'.Str::lower(Str::random(8));

        return [
            'name' => 'فروشگاه '.fake()->firstName(),
            'slug' => $slug,
            'status' => Tenant::STATUS_ACTIVE,
            'trial_ends_at' => null,
            'settings' => ['currency_display' => 'toman', 'digits' => 'fa'],
        ];
    }

    public function trialing(): self
    {
        return $this->state(fn (): array => [
            'status' => Tenant::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => Tenant::STATUS_SUSPENDED]);
    }

    /**
     * Most tests want a tenant that is actually reachable over HTTP.
     */
    public function withDomain(): self
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            Domain::query()->create([
                'tenant_id' => $tenant->getKey(),
                'hostname' => Domain::hostnameFor($tenant->slug),
                'is_primary' => true,
            ]);
        });
    }
}
