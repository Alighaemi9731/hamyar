<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Left to BelongsToTenant when the ambient context has a tenant; tests
            // that need a specific one pass ->for($tenant) or ->forTenant($tenant).
            'name' => fake()->name(),
            'email' => Str::lower(Str::random(10)).'@example.test',
            // Unique per tenant, so a random 9-digit tail is enough and keeps the
            // Iranian 09xxxxxxxxx shape that validation expects.
            'mobile' => '09'.fake()->numerify('#########'),
            'password' => self::$password ??= bcrypt('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function forTenant(Tenant $tenant): self
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->getKey()]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
