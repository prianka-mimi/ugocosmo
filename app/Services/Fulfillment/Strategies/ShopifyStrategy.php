<?php

namespace App\Services\Fulfillment\Strategies;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Fulfillment\Contracts\FulfillmentProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Strategy implementation for Shopify connected store listings.
 *
 * Characteristics:
 * - Fulfillments are synchronized with the merchant's external Shopify store.
 * - Rate requirements are configurable via config('shipping.source_requires_rate.shopify').
 */
class ShopifyStrategy implements FulfillmentProviderInterface
{
    public function getSource(): string
    {
        return 'shopify';
    }

    public function requiresPrePaymentShippingRate(Order|OrderItem|Product $entity): bool
    {
        // By default, Shopify orders are fulfilled via the merchant's Shopify store/fulfillment network,
        // so ShipEngine rate selection is not strictly required unless configured in shipping.php.
        return (bool) config('shipping.source_requires_rate.shopify', false);
    }

    public function validateItemAvailability(Product $product, ?string $countryCode = null, int $quantity = 1, array $options = []): array
    {
        $stock = (int) $product->stock_quantity;
        $available = $stock >= $quantity;

        return [
            'available' => $available,
            'reason' => $available ? null : "Shopify inventory item #{$product->shopify_inventory_item_id} has insufficient stock ({$stock}).",
            'details' => [
                'shopify_product_id' => $product->shopify_product_id,
                'shopify_variant_id' => $product->shopify_variant_id,
                'stock_quantity' => $stock,
                'requested_quantity' => $quantity,
                'source' => 'shopify',
            ],
        ];
    }

    public function fulfill(Order $order, array $data = []): void
    {
        $this->fulfillOrder($order, $data);
    }

    public function fulfillOrder(Order $order, array $data = []): array
    {
        Log::info("Shopify fulfillment triggered for order #{$order->id}");

        // Update local order status to processing awaiting Shopify sync
        $order->update([
            'status' => 'processing',
            'supplier_name' => 'Shopify',
        ]);

        return [
            'success' => true,
            'status' => 'processing',
            'order_id' => $order->id,
            'channel' => 'shopify',
            'message' => 'Order marked for Shopify fulfillment sync.',
        ];
    }
}
