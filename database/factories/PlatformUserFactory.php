<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformUser>
 */
final class PlatformUserFactory extends Factory
{
    protected $model = PlatformUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::lower(Str::random(10)).'@mobishop.test',
            'password' => bcrypt('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }
}
