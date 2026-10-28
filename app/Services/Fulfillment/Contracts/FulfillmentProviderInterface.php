<?php

namespace App\Services\Fulfillment\Contracts;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

/**
 * Contract for modular, multi-source fulfillment providers.
 *
 * Decouples platform-specific fulfillment logic (ShipEngine, AliExpress, Shopify, Etsy, CSV, etc.)
 * from checkout, rate estimation, and payment flows.
 */
interface FulfillmentProviderInterface extends FulfillmentStrategyInterface
{
    /**
     * Get the unique source key identifier (e.g. 'aliexpress', 'shipengine', 'shopify', 'etsy', 'csv', 'manual').
     */
    public function getSource(): string;

    /**
     * Whether items fulfilled by this provider strictly require a pre-payment shipping rate selection
     * before payment credentials (e.g., Stripe PaymentIntent or web checkout session) can be generated.
     */
    public function requiresPrePaymentShippingRate(Order|OrderItem|Product $entity): bool;

    /**
     * Validate product/SKU availability with the upstream provider before processing or placing an order.
     *
     * @param  string|null  $countryCode  ISO-2 destination country
     * @param  array<string, mixed>  $options  Additional validation options (e.g. sku_id)
     * @return array{available: bool, reason: ?string, details: array<string, mixed>}
     */
    public function validateItemAvailability(Product $product, ?string $countryCode = null, int $quantity = 1, array $options = []): array;

    /**
     * Fulfill the order using the provider's specific API/logic.
     *
     * @param  array<string, mixed>  $data  Additional fulfillment options
     * @return array<string, mixed> Result data (tracking info, payment URLs, status, etc.)
     */
    public function fulfillOrder(Order $order, array $data = []): array;
}
