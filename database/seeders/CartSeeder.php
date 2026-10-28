<?php

namespace Database\Seeders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Profile;
use App\Models\Service;
use Illuminate\Database\Seeder;

class CartSeeder extends Seeder
{
    public function run(): void
    {
        $p = $this->profiles();

        $buyers = ['david', 'henry', 'jack'];

        foreach ($buyers as $username) {
            $profile = $p[$username] ?? null;
            if (! $profile) {
                continue;
            }

            $cart = Cart::firstOrCreate(['user_id' => $profile->id], ['user_id' => $profile->id]);

            // Add 2 products to cart
            foreach (Product::where('seller_id', '!=', $profile->id)->take(2)->get() as $product) {
                CartItem::firstOrCreate(['cart_id' => $cart->id, 'product_id' => $product->id], [
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'item_type' => 'product',
                    'title' => $product->title,
                    'image_url' => $product->image_url,
                    'quantity' => 1,
                    'unit_price' => $product->price,
                ]);
            }

            // Add 1 service to cart
            $service = Service::where('provider_id', '!=', $profile->id)->first();
            if ($service) {
                CartItem::firstOrCreate(['cart_id' => $cart->id, 'service_id' => $service->id], [
                    'cart_id' => $cart->id,
                    'service_id' => $service->id,
                    'item_type' => 'service',
                    'title' => $service->title,
                    'quantity' => 1,
                    'unit_price' => $service->price,
                ]);
            }
        }
    }

    private function profiles(): array
    {
        $map = [];
        foreach (['alice', 'bob', 'carol', 'david', 'eva', 'frank', 'grace', 'henry', 'isla', 'jack'] as $u) {
            $pr = Profile::whereHas('user', fn ($q) => $q->where('username', $u))->first();
            if ($pr) {
                $map[$u] = $pr;
            }
        }

        return $map;
    }
}
