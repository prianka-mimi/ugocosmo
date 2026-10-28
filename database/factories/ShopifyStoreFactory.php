<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\ShopifyStore;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<ShopifyStore>
 */
class ShopifyStoreFactory extends Factory
{
    protected $model = ShopifyStore::class;

    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'user_id' => User::factory(),
            'store_domain' => fake()->unique()->domainName().'.myshopify.com',
            'access_token' => Crypt::encryptString('shpat_'.fake()->uuid()),
            'scopes' => 'read_products,write_products,read_inventory',
            'status' => 'active',
            'shop_name' => fake()->company(),
            'currency' => 'USD',
            'country' => 'US',
            'timezone' => 'America/New_York',
            'installed_at' => now(),
            'price_sync_policy' => 'shopify_always',
            'image_sync_strategy' => 'always',
            'inventory_sync_enabled' => true,
        ];
    }

    /**
     * Indicate that the store is disconnected.
     */
    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disconnected',
            'deleted_at' => now(),
        ]);
    }

    /**
     * Indicate that the store has a specific domain.
     */
    public function withDomain(string $domain): static
    {
        return $this->state(fn (array $attributes) => [
            'store_domain' => $domain,
        ]);
    }
}
