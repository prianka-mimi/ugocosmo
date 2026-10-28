<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Fulfillment\Contracts\FulfillmentProviderInterface;
use App\Services\Fulfillment\Strategies\AliExpressFulfillmentStrategy;
use App\Services\Fulfillment\Strategies\EtsyStrategy;
use App\Services\Fulfillment\Strategies\ManualFulfillmentStrategy;
use App\Services\Fulfillment\Strategies\ShipEngineFulfillmentStrategy;
use App\Services\Fulfillment\Strategies\ShopifyStrategy;

/**
 * Dynamic Factory / Resolver Service for Fulfillment Providers.
 *
 * Adheres to the Open/Closed Principle (OCP):
 * Easily extensible for any new 3rd-party marketplace or dropshipping source
 * (e.g. WooCommerce, Amazon, Printful, CJ Dropshipping) without rewriting
 * core checkout or payment controllers.
 */
class FulfillmentProviderResolver
{
    /** @var array<string, FulfillmentProviderInterface> */
    protected array $providers = [];

    public function __construct(
        ShipEngineFulfillmentStrategy $shipEngineStrategy,
        AliExpressFulfillmentStrategy $aliExpressStrategy,
        ShopifyStrategy $shopifyStrategy,
        EtsyStrategy $etsyStrategy,
        ManualFulfillmentStrategy $manualStrategy
    ) {
        $this->registerProvider('shipengine', $shipEngineStrategy);
        $this->registerProvider('local', $shipEngineStrategy); // Local physical inventory defaults to ShipEngine
        $this->registerProvider('csv', $shipEngineStrategy);   // CSV imports default to ShipEngine
        $this->registerProvider('aliexpress', $aliExpressStrategy);
        $this->registerProvider('shopify', $shopifyStrategy);
        $this->registerProvider('etsy', $etsyStrategy);
        $this->registerProvider('manual', $manualStrategy);
    }

    /**
     * Register a fulfillment provider strategy for a given source key.
     */
    public function registerProvider(string $source, FulfillmentProviderInterface $provider): void
    {
        $this->providers[strtolower(trim($source))] = $provider;
    }

    /**
     * Resolve the provider for a specific source key.
     */
    public function resolveBySource(string $source): FulfillmentProviderInterface
    {
        $key = strtolower(trim($source));

        return $this->providers[$key] ?? $this->providers['manual'];
    }

    /**
     * Resolve the provider for a given Product.
     */
    public function resolveForProduct(Product $product): FulfillmentProviderInterface
    {
        // 1. Digital products are fulfilled digitally/manually without carrier logistics
        if (in_array(strtolower((string) $product->product_type), config('shipping.digital_product_types', ['digital', 'digital_download']), true)) {
            return $this->providers['manual'];
        }

        // 2. Explicit manual shipping strategy
        if ($product->shipping_strategy === 'manual') {
            return $this->providers['manual'];
        }

        // 3. AliExpress product signature
        if (strtolower((string) $product->source) === 'aliexpress' || ! empty($product->aliexpress_product_id)) {
            return $this->providers['aliexpress'];
        }

        // 4. Source mapping
        $source = strtolower(trim((string) ($product->source ?: 'local')));

        return $this->resolveBySource($source);
    }

    /**
     * Resolve the provider for a given OrderItem.
     */
    public function resolveForOrderItem(OrderItem $item): FulfillmentProviderInterface
    {
        if (! $item->product_id || ! $item->product) {
            return $this->providers['manual'];
        }

        return $this->resolveForProduct($item->product);
    }

    /**
     * Resolve the primary provider for an entire Order.
     */
    public function resolveForOrder(Order $order): FulfillmentProviderInterface
    {
        $order->loadMissing('items.product');

        if ($order->isAliExpressFulfilled()) {
            return $this->providers['aliexpress'];
        }

        $firstItem = $order->items->first();
        if (! $firstItem || ! $firstItem->product) {
            return $this->providers['manual'];
        }

        return $this->resolveForProduct($firstItem->product);
    }

    /**
     * Determine whether an entire order strictly requires a pre-selected carrier rate
     * before payment credentials can be generated.
     *
     * Decouples ShipEngine carrier rate requirement from AliExpress, Shopify, Etsy, etc.
     */
    public function orderRequiresPrePaymentShippingRate(Order $order): bool
    {
        $order->loadMissing('items.product');

        if ($order->items->isEmpty()) {
            return false;
        }

        // An order mandates pre-payment rate selection IF AND ONLY IF at least one item
        // in the order belongs to a provider that requires pre-payment rates.
        return $order->items->contains(function (OrderItem $item) {
            $provider = $this->resolveForOrderItem($item);

            return $provider->requiresPrePaymentShippingRate($item);
        });
    }
}
