<?php

namespace App\Services;

use App\Models\EscrowPayment;
use App\Models\Order;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PlaceOrderService
{
    /**
     * Handles the buyer checkout process, placing funds into Escrow and setting Order to 'pending'.
     *
     * @throws Throwable
     */
    public function execute(Profile $buyer, array $cartData): Order
    {
        return DB::transaction(function () use ($buyer, $cartData) {
            // Create Order
            $order = Order::create([
                'buyer_id' => $buyer->id,
                'seller_id' => $cartData['seller_id'],
                'status' => 'pending', // Marks order status as 'pending'
                'subtotal' => $cartData['subtotal'],
                'buyer_fee' => $cartData['buyer_fee'] ?? 0,
                'seller_fee' => $cartData['seller_fee'] ?? 0,
                'platform_fee_total' => ($cartData['buyer_fee'] ?? 0) + ($cartData['seller_fee'] ?? 0),
                'tax_amount' => $cartData['tax_amount'] ?? 0,
                'shipping_amount' => $cartData['shipping_amount'] ?? 0,
                'total' => $cartData['total'],
                'shipping_address' => $cartData['shipping_address'],
                'shipping_method' => 'standard',
            ]);

            // Add Items
            foreach ($cartData['items'] as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['quantity'] * $item['unit_price'],
                    // Store AE metadata if available
                    'aliexpress_sku' => $item['aliexpress_sku'] ?? null,
                ]);
            }

            // Create Escrow Payment record
            EscrowPayment::create([
                'order_id' => $order->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $order->seller_id,
                'amount' => $order->total,
                'payment_reference' => 'ch_'.Str::random(16), // Mock payment ref
                'status' => 'pending', // Payment goes to Escrow
            ]);

            return $order;
        });
    }
}
