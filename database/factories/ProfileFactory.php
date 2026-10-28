<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'full_name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'bio' => fake()->sentence(),
            'is_seller' => false,
        ];
    }

    public function seller(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_seller' => true,
        ]);
    }
}
