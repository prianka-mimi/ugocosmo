<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->firstName().' '.fake()->lastName(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'mobile_number' => fake()->phoneNumber(),
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'country' => fake()->country(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}
