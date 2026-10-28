<?php

namespace Database\Factories;

use App\Models\AliExpressAccount;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<AliExpressAccount>
 */
class AliExpressAccountFactory extends Factory
{
    protected $model = AliExpressAccount::class;

    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'user_id' => User::factory(),
            'aliexpress_member_id' => (string) fake()->unique()->numberBetween(1000000000, 9999999999),
            'account_name' => fake()->userName(),
            'access_token' => Crypt::encryptString('aliexpress_'.fake()->uuid()),
            'refresh_token' => Crypt::encryptString('refresh_'.fake()->uuid()),
            'token_expires_at' => now()->addHours(23),
            'refresh_token_valid_until' => now()->addDays(170),
            'region' => 'sg',
            'status' => AliExpressAccount::STATUS_ACTIVE,
            'connected_at' => now(),
        ];
    }

    public function withExpiredToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'token_expires_at' => now()->subHour(),
            'refresh_token_valid_until' => now()->addDays(10),
        ]);
    }
}
