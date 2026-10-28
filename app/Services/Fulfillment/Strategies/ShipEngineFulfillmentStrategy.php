<?php

namespace App\Services\Fulfillment\Strategies;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Fulfillment\Contracts\FulfillmentProviderInterface;
use App\Services\ShipEngineService;

class ShipEngineFulfillmentStrategy implements FulfillmentProviderInterface
{
    public function __construct(
        protected ShipEngineService $shipEngineService
    ) {}

    public function getSource(): string
    {
        return 'shipengine';
    }

    public function requiresPrePaymentShippingRate(Order|OrderItem|Product $entity): bool
    {
        if ($entity instanceof Order) {
            $entity->loadMissing('items.product');

            // If the order already has a preset/calculated shipping method and shipping amount from checkout,
            // rate selection is already complete — dynamic pre-payment carrier rate selection is NOT required.
            if (! empty($entity->shipping_method) && $entity->shipping_amount !== null) {
                return false;
            }

            return $entity->items->contains(fn (OrderItem $item) => $this->requiresPrePaymentShippingRate($item));
        }

        if ($entity instanceof OrderItem) {
            if (! $entity->product_id || ! $entity->product) {
                return false;
            }

            return $this->requiresPrePaymentShippingRate($entity->product);
        }

        // Entity is Product
        if (in_array(strtolower((string) $entity->product_type), config('shipping.digital_product_types', ['digital', 'digital_download']), true)) {
            return false;
        }

        if ($entity->shipping_strategy === 'manual') {
            return false;
        }

        // Local, self-created, and shipengine-sourced physical products require
        // ShipEngine carrier rate selection. Only explicitly manual shipping_strategy
        // or digital types are excluded (handled above).
        $source = strtolower((string) ($entity->source ?: 'local'));

        // Dropshipped external sources do not use ShipEngine rates.
        if (in_array($source, ['aliexpress', 'shopify', 'etsy'], true)) {
            return false;
        }

        return true;
    }

    public function validateItemAvailability(Product $product, ?string $countryCode = null, int $quantity = 1, array $options = []): array
    {
        $stock = (int) $product->stock_quantity;
        $available = $stock >= $quantity;

        return [
            'available' => $available,
            'reason' => $available ? null : "Requested quantity ({$quantity}) exceeds available stock ({$stock}).",
            'details' => [
                'stock_quantity' => $stock,
                'requested_quantity' => $quantity,
                'is_active' => (bool) $product->is_active,
                'source' => 'shipengine',
            ],
        ];
    }

    public function fulfill(Order $order, array $data = []): void
    {
        $this->fulfillOrder($order, $data);
    }

    public function fulfillOrder(Order $order, array $data = []): array
    {
        // Automatically generate a shipping label using the ShipEngine API
        $sellerId = $order->seller_id;

        $shipment = $this->shipEngineService->create($order, $sellerId);

        $rateId = $order->selected_rate_id;
        if (empty($rateId) && empty($order->selected_carrier_id)) {
            throw new \InvalidArgumentException("Cannot fulfill shipment: Order #{$order->id} does not have a buyer-selected shipping rate.");
        }

        // Purchase label strictly using buyer-selected shipping rate chosen at checkout
        $purchaseResult = $this->shipEngineService->purchaseLabel(
            $shipment,
            $sellerId,
            $rateId,
            $order->selected_carrier_id,
            $order->selected_service_code,
            $data['label_format'] ?? 'pdf',
            $data['label_layout'] ?? '4x6',
            $data['label_download_type'] ?? 'url'
        );

        return [
            'success' => true,
            'shipment_id' => $shipment->id,
            'tracking_number' => $shipment->fresh()->tracking_number,
            'label_url' => $purchaseResult['label_download_url'] ?? $shipment->label_download_url,
            'carrier_code' => $shipment->carrier_code,
            'status' => $order->fresh()->status,
        ];
    }
}
