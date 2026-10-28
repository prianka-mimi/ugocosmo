<?php

namespace App\Services\Fulfillment\Strategies;

use App\Models\AliExpressAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\AliExpressOrderClient;
use App\Services\AliExpressOrderFulfillmentService;
use App\Services\AliExpressProductClient;
use App\Services\Fulfillment\Contracts\FulfillmentProviderInterface;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Strategy implementation for AliExpress dropshipped orders.
 *
 * Characteristics:
 * - Dropshipped directly by the AliExpress supplier.
 * - DOES NOT require pre-payment carrier rate selection via ShipEngine.
 * - Validates product and SKU availability against the AliExpress catalog before placing orders.
 * - Places supplier orders via AliExpress Dropshipping API and returns the official supplier Pay Now link.
 */
class AliExpressFulfillmentStrategy implements FulfillmentProviderInterface
{
    public function __construct(
        protected AliExpressOrderFulfillmentService $fulfillmentService,
        protected AliExpressOrderClient $orderClient,
        protected AliExpressProductClient $productClient
    ) {}

    public function getSource(): string
    {
        return 'aliexpress';
    }

    /**
     * AliExpress dropshipped products never require pre-payment ShipEngine shipping rate selection.
     */
    public function requiresPrePaymentShippingRate(Order|OrderItem|Product $entity): bool
    {
        return false;
    }

    /**
     * Validate product and SKU availability on AliExpress before creating a supplier order.
     * Prevents [PRODUCT_NOT_EXIST] by diagnosing delisted items, invalid product IDs, and country restrictions.
     */
    public function validateItemAvailability(Product $product, ?string $countryCode = null, int $quantity = 1, array $options = []): array
    {
        $rawId = $product->aliexpress_product_id ?: ($product->aliexpress_source_data['product_id'] ?? null);

        if (empty($rawId)) {
            return [
                'available' => false,
                'reason' => 'Product is missing an authentic AliExpress product ID.',
                'details' => ['product_id' => $product->id, 'error' => 'MISSING_PRODUCT_ID'],
            ];
        }

        // Sanitize numeric product ID if a full URL was stored
        $productId = $this->sanitizeProductId($rawId);
        if ($productId === '') {
            return [
                'available' => false,
                'reason' => "Invalid AliExpress product ID format: '{$rawId}'.",
                'details' => ['raw_id' => $rawId, 'error' => 'INVALID_ID_FORMAT'],
            ];
        }

        $destCountry = strtoupper(trim((string) ($countryCode ?: 'US')));
        if (strlen($destCountry) !== 2) {
            $destCountry = 'US';
        }

        // Retrieve seller account to make authenticated check
        $account = null;
        if ($product->seller_id) {
            $account = AliExpressAccount::byUser($product->seller_id)->active()->latest('connected_at')->first()
                ?? AliExpressAccount::byUser($product->seller_id)->latest('id')->first();
        }

        if (! $account) {
            $account = AliExpressAccount::active()->latest('connected_at')->first();
        }

        if (! $account) {
            // If no account is connected, fall back to basic ID presence
            return [
                'available' => true,
                'reason' => null,
                'details' => ['product_id' => $productId, 'notice' => 'No active AliExpress account connected to verify live catalog.'],
            ];
        }

        try {
            $detail = $this->productClient->detail($account, $destCountry, $productId, forceRefresh: false, enrich: false);

            if (! $detail || empty($detail->productId)) {
                return [
                    'available' => false,
                    'reason' => "Product #{$productId} was not found on AliExpress or has been delisted by the supplier.",
                    'details' => [
                        'product_id' => $productId,
                        'ship_to_country' => $destCountry,
                        'error_code' => 'PRODUCT_NOT_EXIST',
                    ],
                ];
            }

            // Check SKUs and inventory if requested
            $requestedSku = $options['sku_id'] ?? $options['sku'] ?? $product->aliexpress_sku ?? null;
            if ($requestedSku && ! empty($detail->skus)) {
                $matchedSku = null;
                foreach ($detail->skus as $skuDto) {
                    if ($skuDto->skuId === (string) $requestedSku || (string) $skuDto->skuCode === (string) $requestedSku) {
                        $matchedSku = $skuDto;
                        break;
                    }
                }

                if ($matchedSku && $matchedSku->stock < $quantity) {
                    return [
                        'available' => false,
                        'reason' => "Selected variant (SKU: {$requestedSku}) is currently out of stock on AliExpress.",
                        'details' => [
                            'sku_id' => $requestedSku,
                            'available_stock' => $matchedSku->stock,
                            'requested_quantity' => $quantity,
                            'error_code' => 'OUT_OF_STOCK',
                        ],
                    ];
                }
            }

            return [
                'available' => true,
                'reason' => null,
                'details' => [
                    'product_id' => $detail->productId,
                    'subject' => $detail->subject,
                    'sku_count' => count($detail->skus),
                    'ship_to_country' => $destCountry,
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning("AliExpress item availability pre-check failed for product #{$productId}: ".$e->getMessage());

            if (str_contains($e->getMessage(), 'no detail') || str_contains($e->getMessage(), 'NOT_EXIST')) {
                return [
                    'available' => false,
                    'reason' => "Product #{$productId} does not exist on AliExpress or is not deliverable to {$destCountry}.",
                    'details' => ['product_id' => $productId, 'error_code' => 'PRODUCT_NOT_EXIST'],
                ];
            }

            // Network / API failure: allow downstream to attempt execution with full error handling
            return [
                'available' => true,
                'reason' => null,
                'details' => ['warning' => $e->getMessage()],
            ];
        }
    }

    public function fulfill(Order $order, array $data = []): void
    {
        $this->fulfillOrder($order, $data);
    }

    public function fulfillOrder(Order $order, array $data = []): array
    {
        $seller = $order->seller;
        $userId = $seller?->user_id ?: $order->seller_id;

        $account = AliExpressAccount::byUser((int) $userId)->active()->latest('connected_at')->first()
            ?? AliExpressAccount::byUser((int) $userId)->latest('id')->first();

        if (! $account) {
            throw new Exception('No connected AliExpress account found for the seller of this order.', 422);
        }

        return $this->fulfillmentService->execute($order, $account, $data);
    }

    protected function sanitizeProductId(mixed $raw): string
    {
        $str = trim((string) $raw);

        if (preg_match('#(?:item/|productId=|id=)?(\d{8,})#i', $str, $matches)) {
            return $matches[1];
        }

        return ctype_digit($str) ? $str : '';
    }
}
