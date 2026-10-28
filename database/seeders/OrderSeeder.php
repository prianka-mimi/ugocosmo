<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = $this->profiles();

        // [buyer, seller] pairs
        $pairs = [
            ['david', 'alice'],
            ['david', 'eva'],
            ['jack',  'henry'],
            ['henry', 'alice'],
            ['isla',  'jack'],
            ['frank', 'eva'],
            ['bob',   'grace'],
            ['carol', 'alice'],
        ];

        $statuses = ['completed', 'completed', 'pending', 'completed', 'pending', 'completed', 'pending', 'completed'];

        foreach ($pairs as $i => [$buyerKey, $sellerKey]) {
            $buyer = $profiles[$buyerKey] ?? null;
            $seller = $profiles[$sellerKey] ?? null;
            if (! $buyer || ! $seller) {
                continue;
            }

            $product = Product::where('seller_id', $seller->id)->first();
            if (! $product) {
                continue;
            }

            $order = Order::firstOrCreate(['buyer_id' => $buyer->id, 'seller_id' => $seller->id], [
                'buyer_id' => $buyer->id,
                'seller_id' => $seller->id,
                'status' => $statuses[$i] ?? 'pending',
                'subtotal' => $product->price,
                'buyer_fee' => round($product->price * 0.03, 2),
                'seller_fee' => round($product->price * 0.05, 2),
                'platform_fee_total' => round($product->price * 0.08, 2),
                'tax_amount' => 0.00,
                'total' => round($product->price * 1.03, 2),
                'shipping_address' => json_encode(['line1' => '123 Main St', 'city' => 'London', 'country' => 'GB']),
            ]);

            OrderItem::firstOrCreate(['order_id' => $order->id, 'product_id' => $product->id], [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'title' => $product->title,
                'quantity' => 1,
                'unit_price' => $product->price,
                'total_price' => $product->price,
            ]);
        }
    }

    private function profiles(): array
    {
        $map = [];
        foreach (['alice', 'bob', 'carol', 'david', 'eva', 'frank', 'grace', 'henry', 'isla', 'jack'] as $u) {
            $p = Profile::whereHas('user', fn ($q) => $q->where('username', $u))->first();
            if ($p) {
                $map[$u] = $p;
            }
        }

        return $map;
    }
}
