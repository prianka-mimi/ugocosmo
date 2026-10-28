<?php

namespace App\Services\Fulfillment\Strategies;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Fulfillment\Contracts\FulfillmentProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Strategy implementation for Etsy connected shop listings.
 *
 * Characteristics:
 * - Fulfillments are synchronized with the merchant's external Etsy shop.
 * - Rate requirements are configurable via config('shipping.source_requires_rate.etsy').
 */
class EtsyStrategy implements FulfillmentProviderInterface
{
    public function getSource(): string
    {
        return 'etsy';
    }

    public function requiresPrePaymentShippingRate(Order|OrderItem|Product $entity): bool
    {
        // By default, Etsy orders are fulfilled via the merchant's Etsy shop shipping profiles,
        // so ShipEngine rate selection is not strictly required unless configured in shipping.php.
        return (bool) config('shipping.source_requires_rate.etsy', false);
    }

    public function validateItemAvailability(Product $product, ?string $countryCode = null, int $quantity = 1, array $options = []): array
    {
        $isActive = (bool) $product->is_active && $product->etsy_state === 'active';
        $stock = (int) $product->stock_quantity;
        $available = $isActive && ($stock === 0 || $stock >= $quantity);

        return [
            'available' => $available,
            'reason' => $available ? null : "Etsy listing #{$product->etsy_listing_id} is inactive or out of stock.",
            'details' => [
                'etsy_listing_id' => $product->etsy_listing_id,
                'etsy_state' => $product->etsy_state,
                'stock_quantity' => $stock,
                'source' => 'etsy',
            ],
        ];
    }

    public function fulfill(Order $order, array $data = []): void
    {
        $this->fulfillOrder($order, $data);
    }

    public function fulfillOrder(Order $order, array $data = []): array
    {
        Log::info("Etsy fulfillment triggered for order #{$order->id}");

        // Update local order status to processing awaiting Etsy fulfillment
        $order->update([
            'status' => 'processing',
            'supplier_name' => 'Etsy',
        ]);

        return [
            'success' => true,
            'status' => 'processing',
            'order_id' => $order->id,
            'channel' => 'etsy',
            'message' => 'Order marked for Etsy fulfillment sync.',
        ];
    }
}
