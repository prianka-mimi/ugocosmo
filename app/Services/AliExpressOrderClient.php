<?php

namespace App\Services;

use App\Actions\FormatAliExpressAddressAction;
use App\Models\AliExpressAccount;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class AliExpressOrderClient
{
    public function __construct(
        protected AliExpressApiClient $client,
        protected AliExpressTokenManager $tokens
    ) {}

    /**
     * Pushes the order to AliExpress Dropshipping API.
     * Maps to aliexpress.ds.order.create
     *
     * The API requires a single DTO parameter:
     * param_place_order_request4_open_api_d_t_o
     *
     * @return array{order_id: string, payment_url: string}
     */
    public function createOrder(
        AliExpressAccount $account,
        Order $order,
        array $logisticsOptions,
        bool $isRetry = false,
        bool $preferStateAbbr = false,
        int $addressAttempt = 0,
        int $deliveryAttempt = 0
    ): array {
        $order->loadMissing('items.product');

        // Build product_items list from AliExpress-sourced order items
        $productItems = [];
        foreach ($order->items as $item) {
            if (! $item->product || $item->product->source !== 'aliexpress') {
                continue;
            }

            $rawProductId = $item->product->aliexpress_product_id
                ?: ($item->product->aliexpress_source_data['product_id'] ?? null);

            if (is_string($rawProductId) && preg_match('#(?:item/|productId=|id=)?(\d{8,})#i', $rawProductId, $matches)) {
                $rawProductId = $matches[1];
            }

            if (empty($rawProductId) || ! is_numeric($rawProductId)) {
                Log::warning("Order item #{$item->id} product has invalid aliexpress_product_id: {$rawProductId}");

                continue;
            }

            $skuAttr = $this->resolveSkuAttr($item, $account, $logisticsOptions, $isRetry, $order);

            $logisticsService = $this->resolveLogisticsServiceName(
                $item,
                $account,
                $order,
                $logisticsOptions,
                $deliveryAttempt
            );

            $itemPayload = [
                'product_id' => (int) $rawProductId,
                'product_count' => max(1, (int) $item->quantity),
                'logistics_service_name' => (string) $logisticsService,
            ];

            if ($skuAttr !== '') {
                $itemPayload['sku_attr'] = $skuAttr;
            }

            $itemPayload['order_memo'] = "Drop shipping order #{$order->id}";

            $productItems[] = $itemPayload;
        }

        if (empty($productItems)) {
            throw new \Exception('No AliExpress products found in order.');
        }

        $shippingOverride = $logisticsOptions['shipping_address'] ?? [];
        $logisticsAddress = $this->buildLogisticsAddress($order, $shippingOverride, $preferStateAbbr);

        $dto = [
            'logistics_address' => $logisticsAddress,
            'product_items' => $productItems,
        ];

        $params = [
            'param_place_order_request4_open_api_d_t_o' => json_encode(
                $dto,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ];

        $method = config('services.aliexpress.methods.order_create', 'aliexpress.ds.order.create');

        // flatParams=true: param_place_order_request4_open_api_d_t_o must be
        // a flat top-level POST field, NOT wrapped inside param_json.
        $response = $this->callFlat($account, $method, $params);

        Log::info('AliExpress DS order create raw response', [
            'order_id' => $order->id,
            'is_retry' => $isRetry,
            'prefer_state_abbr' => $preferStateAbbr,
            'address_attempt' => $addressAttempt,
            'logistics_address' => $logisticsAddress,
            'response' => $response,
        ]);

        // AliExpress DS order create response shape:
        // { "result": { "order_list": [123456], "is_success": true }, "target_order_list": [...] }
        // OR: { "result": { "order_id": 123456, "payment_url": "https://..." } }
        $result = $response['result'] ?? $response['resp_result']['result'] ?? $response;

        // 1. Check for AliExpress business failure in result payload
        if (isset($result['is_success']) && ! filter_var($result['is_success'], FILTER_VALIDATE_BOOLEAN)) {
            $errCode = (string) ($result['error_code'] ?? $result['sub_code'] ?? $result['code'] ?? 'ORDER_CREATE_ERROR');
            $errMsg = (string) ($result['error_msg'] ?? $result['sub_msg'] ?? $result['msg'] ?? $result['message'] ?? 'AliExpress order creation rejected by supplier.');

            // 1a. If error is SKU_NOT_EXIST or PRODUCT_NOT_EXIST and this is the first attempt, auto-recover by force-refreshing
            // live active SKUs and detail from AliExpress and retrying once.
            $isProductOrSkuError = (bool) preg_match('/(SKU_NOT_EXIST|PRODUCT_NOT_EXIST|ITEM_NOT_EXIST|ITEM_HAS_DELETED)/iu', $errCode.' '.$errMsg);
            if (! $isRetry && $isProductOrSkuError) {
                Log::warning("AliExpress rejected order with {$errCode} ({$errMsg}), refreshing live catalog and retrying once...", [
                    'order_id' => $order->id,
                    'error_code' => $errCode,
                    'error_msg' => $errMsg,
                ]);

                $shippingAddress = is_array($order->shipping_address) ? $order->shipping_address : [];
                $destCountry = strtoupper(trim((string) ($shippingAddress['country_code'] ?? $shippingAddress['country'] ?? 'US')));
                if (strlen($destCountry) !== 2) {
                    $destCountry = 'US';
                }

                foreach ($order->items as $item) {
                    $rawPid = $item->product?->aliexpress_product_id;
                    if (! empty($rawPid)) {
                        cache()->forget("aliexpress:dto_v4:{$rawPid}:{$destCountry}");
                        cache()->forget("aliexpress:dto_v4:{$rawPid}:{$destCountry}:no_enrich");
                    }
                }

                return $this->createOrder(
                    $account,
                    $order,
                    $logisticsOptions,
                    isRetry: true,
                    preferStateAbbr: $preferStateAbbr,
                    addressAttempt: $addressAttempt,
                    deliveryAttempt: $deliveryAttempt
                );
            }

            // 1b. If error is state/province/county/city validation or build-for-place-order failure, retry up to 4 times
            // with different address format strategies before giving up.
            $isAddressValidation = (bool) preg_match(
                '/(PROVINCE|STATE|COUNTY|State\/Province|CITY|Please enter a City|DELIVERY_ADDRESS_VALIDATE|ERROR_WHEN_BUILD_FOR_PLACE_ORDER|BUILD_FOR_PLACE_ORDER|\x{c8fc}\/\x{b3c4}|\x{c9c0}\x{c5ed})/iu',
                $errCode.' '.$errMsg
            );

            if ($isAddressValidation && $addressAttempt < 4) {
                $nextAttempt = $addressAttempt + 1;
                $nextOptions = $logisticsOptions;
                $shipping = $nextOptions['shipping_address'] ?? [];
                $shipping['_gb_strategy'] = $nextAttempt;
                $shipping['_city_strategy'] = $nextAttempt;
                $shipping['_address_strategy'] = $nextAttempt;
                $nextOptions['shipping_address'] = $shipping;

                $nextPreferAbbr = ($nextAttempt % 2 === 1);

                // If error is BUILD_FOR_PLACE_ORDER, also bust freight & SKU caches so live data is refreshed
                if (str_contains(strtoupper($errCode.' '.$errMsg), 'BUILD_FOR_PLACE_ORDER')) {
                    $shippingAddress = is_array($order->shipping_address) ? $order->shipping_address : [];
                    $destCountry = strtoupper(trim((string) ($shippingAddress['country_code'] ?? $shippingAddress['country'] ?? 'US')));
                    if (strlen($destCountry) !== 2) {
                        $destCountry = 'US';
                    }
                    foreach ($order->items as $item) {
                        $rawPid = $item->product?->aliexpress_product_id;
                        if (! empty($rawPid)) {
                            cache()->forget("aliexpress:live_freight:{$account->id}:{$rawPid}:{$destCountry}");
                            cache()->forget("aliexpress:live_freight:{$rawPid}:{$destCountry}");
                            cache()->forget("aliexpress:dto_v4:{$rawPid}:{$destCountry}");
                            cache()->forget("aliexpress:dto_v4:{$rawPid}:{$destCountry}:no_enrich");
                        }
                    }
                }

                Log::warning("AliExpress rejected order due to address/build validation ({$errMsg}), retrying with address strategy {$nextAttempt}...", [
                    'order_id' => $order->id,
                    'current_attempt' => $addressAttempt,
                    'next_attempt' => $nextAttempt,
                    'error_code' => $errCode,
                    'error_msg' => $errMsg,
                    'previous_logistics_address' => $logisticsAddress,
                ]);

                return $this->createOrder(
                    $account,
                    $order,
                    $nextOptions,
                    isRetry: true,
                    preferStateAbbr: $nextPreferAbbr,
                    addressAttempt: $nextAttempt,
                    deliveryAttempt: $deliveryAttempt
                );
            }

            // 1c. If error is DELIVERY_METHOD_NOT_EXIST / SHIPPING_METHOD failure, retry up to 3 times
            // with alternative logistics service candidates, busting the freight cache on first retry.
            $isDeliveryMethodError = (bool) preg_match(
                '/(DELIVERY_METHOD_NOT_EXIST|DELIVERY_METHOD|LOGISTICS_SERVICE|SHIPPING_METHOD)/iu',
                $errCode.' '.$errMsg
            );

            if ($isDeliveryMethodError && $deliveryAttempt < 3) {
                $nextDeliveryAttempt = $deliveryAttempt + 1;

                // Bust the freight cache on first delivery retry so we get fresh shipping methods
                if ($nextDeliveryAttempt === 1) {
                    $shippingAddress = is_array($order->shipping_address) ? $order->shipping_address : [];
                    $destCountry = strtoupper(trim((string) ($shippingAddress['country_code'] ?? $shippingAddress['country'] ?? 'US')));
                    if (strlen($destCountry) !== 2) {
                        $destCountry = 'US';
                    }
                    foreach ($order->items as $item) {
                        $rawProductId = $item->product?->aliexpress_product_id;
                        if (! empty($rawProductId) && is_numeric($rawProductId)) {
                            // Bust both old and new cache key formats
                            cache()->forget("aliexpress:live_freight:{$account->id}:{$rawProductId}:{$destCountry}");
                            cache()->forget("aliexpress:live_freight:{$rawProductId}:{$destCountry}");
                        }
                    }
                }

                Log::warning("AliExpress rejected order due to delivery method ({$errMsg}), retrying with alternative logistics candidate attempt {$nextDeliveryAttempt}...", [
                    'order_id' => $order->id,
                    'current_delivery_attempt' => $deliveryAttempt,
                    'next_delivery_attempt' => $nextDeliveryAttempt,
                    'error_code' => $errCode,
                    'error_msg' => $errMsg,
                ]);

                return $this->createOrder(
                    $account,
                    $order,
                    $logisticsOptions,
                    isRetry: true,
                    preferStateAbbr: $preferStateAbbr,
                    addressAttempt: $addressAttempt,
                    deliveryAttempt: $nextDeliveryAttempt
                );
            }

            Log::error('AliExpress DS order create business error', [
                'order_id' => $order->id,
                'error_code' => $errCode,
                'error_msg' => $errMsg,
                'result' => $result,
            ]);

            if ($errCode === 'DELIVERY_METHOD_NOT_EXIST' || str_contains($errMsg, 'DELIVERY_METHOD_NOT_EXIST')) {
                $shippingAddress = is_array($order->shipping_address) ? $order->shipping_address : [];
                $destCountry = strtoupper(trim((string) ($shippingAddress['country_code'] ?? $shippingAddress['country'] ?? '')));
                $countryLabel = $destCountry !== '' ? $destCountry : 'the destination country';
                throw new \Exception("[DELIVERY_METHOD_NOT_EXIST] This AliExpress supplier does not ship to {$countryLabel} for one or more products in this order. Please update the shipping address to a supported country, or replace the product with one that ships to {$countryLabel}.", 422);
            }

            if ($errCode === 'PRODUCT_NOT_EXIST' || str_contains($errMsg, 'PRODUCT_NOT_EXIST')) {
                $shippingAddress = is_array($order->shipping_address) ? $order->shipping_address : [];
                $destCountry = strtoupper(trim((string) ($shippingAddress['country_code'] ?? $shippingAddress['country'] ?? '')));
                $countryLabel = $destCountry !== '' ? " for destination country [{$destCountry}]" : '';
                throw new \Exception("[PRODUCT_NOT_EXIST] The AliExpress product or specific variant is not available from the supplier{$countryLabel}. The product may have been delisted on AliExpress, restricted in the target region, or the stored product ID/SKU is invalid. Please select an active product.", 422);
            }

            throw new \Exception("AliExpress supplier order creation failed: [{$errCode}] {$errMsg}");
        }

        // 2. Robustly extract all order IDs from any response shape
        $orderIds = $this->extractOrderIds($response);
        $orderId = ! empty($orderIds) ? (string) $orderIds[0] : '';

        // Check if an explicit payment URL was returned by AliExpress
        $explicitUrl = $result['payment_url']
            ?? $result['redirect_url']
            ?? $result['pay_url']
            ?? $result['cashier_url']
            ?? $response['payment_url']
            ?? null;

        $paymentUrl = ! empty($explicitUrl)
            ? (string) $explicitUrl
            : $this->buildPaymentUrl($orderId, $orderIds);

        return [
            'order_id' => $orderId,
            'payment_url' => $paymentUrl,
            'payment_urls' => $this->buildPaymentUrls($orderId, $orderIds),
            'order_list' => $orderIds,
            'raw_result' => $result,
        ];
    }

    /**
     * Robustly extract all AliExpress order IDs from any response shape.
     * Supports:
     * - order_list: [123, 456]
     * - order_list: {"number": [123, 456]}
     * - order_list: {"number": 123}
     * - target_order_list: {"number": [...]}
     * - order_id: 123
     * - ae_order_id: 123
     *
     * @return array<string>
     */
    public function extractOrderIds(array $response): array
    {
        $result = $response['result'] ?? $response['resp_result']['result'] ?? $response;
        $orderIds = [];

        // Candidates for order lists
        $candidates = [
            $result['order_list'] ?? null,
            $result['target_order_list'] ?? null,
            $response['order_list'] ?? null,
            $response['target_order_list'] ?? null,
            $response['aliexpress_ds_order_create_response']['result']['order_list'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (empty($candidate)) {
                continue;
            }

            // Alibaba TOP XML-to-JSON serialization: {"number": [12345]} or {"number": 12345}
            if (isset($candidate['number'])) {
                $raw = $candidate['number'];
                $list = is_array($raw) ? $raw : [$raw];
                foreach ($list as $id) {
                    if (! empty($id)) {
                        $orderIds[] = (string) $id;
                    }
                }
            } elseif (is_array($candidate)) {
                foreach ($candidate as $item) {
                    if (is_scalar($item) && is_numeric($item)) {
                        $orderIds[] = (string) $item;
                    } elseif (is_array($item)) {
                        $id = $item['order_id'] ?? $item['number'] ?? $item['id'] ?? $item['ae_order_id'] ?? null;
                        if (! empty($id)) {
                            $orderIds[] = (string) $id;
                        }
                    }
                }
            } elseif (is_scalar($candidate) && is_numeric($candidate)) {
                $orderIds[] = (string) $candidate;
            }
        }

        // Single order ID keys
        $singleKeys = ['order_id', 'ae_order_id', 'target_order_id', 'trade_order_id', 'alipay_order_id'];
        foreach ($singleKeys as $key) {
            if (! empty($result[$key])) {
                $orderIds[] = (string) $result[$key];
            }
            if (! empty($response[$key])) {
                $orderIds[] = (string) $response[$key];
            }
        }

        // Fallback: Recursive scan
        if (empty($orderIds)) {
            array_walk_recursive($response, function ($value, $key) use (&$orderIds) {
                if (in_array($key, ['order_id', 'ae_order_id', 'target_order_id'], true) && ! empty($value) && is_scalar($value)) {
                    $orderIds[] = (string) $value;
                }
            });
        }

        return array_values(array_unique(array_filter($orderIds)));
    }

    /**
     * Build the primary payment confirmation URL.
     */
    public function buildPaymentUrl(string $orderId, array $orderIds = []): string
    {
        if ($orderId === '' && ! empty($orderIds)) {
            $orderId = (string) reset($orderIds);
        }

        if ($orderId !== '') {
            return "https://www.aliexpress.com/p/order/detail.html?orderId={$orderId}";
        }

        return 'https://www.aliexpress.com/p/order/index.html';
    }

    /**
     * Build a collection of helpful AliExpress payment/order URLs.
     */
    public function buildPaymentUrls(string $orderId, array $orderIds = []): array
    {
        $firstId = $orderId !== '' ? $orderId : (! empty($orderIds) ? (string) reset($orderIds) : '');

        return [
            'detail' => $firstId !== '' ? "https://www.aliexpress.com/p/order/detail.html?orderId={$firstId}" : 'https://www.aliexpress.com/p/order/index.html',
            'orders_list' => 'https://www.aliexpress.com/p/order/index.html',
            'checkout' => $firstId !== '' ? "https://www.aliexpress.com/p/order/detail.html?orderId={$firstId}" : 'https://www.aliexpress.com/p/order/index.html',
            'orders_list_classic' => 'https://trade.aliexpress.com/orderList.htm',
        ];
    }

    /**
     * Resolve a valid logistics service name for an order item.
     * Prevents DELIVERY_METHOD_NOT_EXIST by querying live freight methods
     * supported by the product for destination country, and cycling through authentic candidates.
     */
    protected function resolveLogisticsServiceName(
        $item,
        AliExpressAccount $account,
        Order $order,
        array $options,
        int $attempt = 0
    ): string {
        $override = $options['logistics_options'][$item->id]
            ?? $options['logistics_options'][(string) $item->id]
            ?? ($item->product_id ? ($options['logistics_options'][$item->product_id] ?? null) : null)
            ?? ($options[$item->id] ?? null);

        if (! empty($override) && is_string($override) && $attempt === 0) {
            return trim($override);
        }

        $candidates = [];

        $product = $item->product;
        $rawProductId = $product?->aliexpress_product_id;
        $shippingAddress = is_array($order->shipping_address) ? $order->shipping_address : [];
        $destCountry = strtoupper(trim((string) ($shippingAddress['country_code'] ?? $shippingAddress['country'] ?? 'US')));
        if (strlen($destCountry) !== 2) {
            $destCountry = 'US';
        }

        if (! empty($rawProductId) && is_numeric($rawProductId)) {
            try {
                // Cache key includes account_id to prevent cross-user cache hits
                $cacheKey = "aliexpress:live_freight:{$account->id}:{$rawProductId}:{$destCountry}";
                $freightMethods = cache()->remember($cacheKey, 300, function () use ($account, $destCountry, $rawProductId, $item, $product) {
                    $skuId = null;
                    $variants = is_array($product?->product_variants) ? $product->product_variants : (json_decode($product?->product_variants ?? '', true) ?: []);
                    if (! empty($variants)) {
                        $skuId = $variants[0]['sku_id'] ?? $variants[0]['sku'] ?? null;
                    }
                    $productClient = app(AliExpressProductClient::class);

                    return $productClient->freight($account, $destCountry, (string) $rawProductId, max(1, (int) $item->quantity), skuId: $skuId);
                });

                foreach ($freightMethods as $m) {
                    if (! empty($m->serviceName) && ! in_array($m->serviceName, $candidates, true)) {
                        $candidates[] = $m->serviceName;
                    }
                    if (! empty($m->company) && ! in_array($m->company, $candidates, true)) {
                        $candidates[] = $m->company;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Could not query live freight methods for item #{$item->id}: ".$e->getMessage());
            }
        }

        $standardFallbacks = [
            'AliExpress Standard Shipping',
            'CAINIAO_STANDARD',
        ];

        foreach ($standardFallbacks as $fb) {
            if (! in_array($fb, $candidates, true)) {
                $candidates[] = $fb;
            }
        }

        $chosen = $candidates[$attempt % count($candidates)];

        Log::info("Resolved logistics service name for order #{$order->id} item #{$item->id}", [
            'attempt' => $attempt,
            'chosen' => $chosen,
            'candidates' => $candidates,
        ]);

        return $chosen;
    }

    /**
     * Resolve a valid, authentic AliExpress SKU attribute string for an order item.
     * Prevents SKU_NOT_EXIST by inspecting request overrides, stored product variants,
     * source SKU mappings, or querying the AliExpress product API live.
     */
    protected function resolveSkuAttr(
        $item,
        AliExpressAccount $account,
        array $options,
        bool $forceRefresh = false,
        ?Order $order = null
    ): string {
        $overrideKey = null;

        // 1. Explicit override from request
        $skuOptions = $options['sku_options'] ?? $options['sku_map'] ?? $options['skus'] ?? [];
        if (is_array($skuOptions)) {
            $requested = $skuOptions[$item->id]
                ?? $skuOptions[(string) $item->id]
                ?? ($item->product_id ? ($skuOptions[$item->product_id] ?? $skuOptions[(string) $item->product_id] ?? null) : null)
                ?? null;

            if (! empty($requested) && is_string($requested)) {
                $sanitized = $this->sanitizeSkuAttr($requested);
                if ($sanitized !== '') {
                    return $sanitized;
                }
                $overrideKey = trim($requested);
            }
        }

        $product = $item->product;
        if (! $product) {
            return '';
        }

        if (! $forceRefresh) {
            // 2. Product stored aliexpress_sku (only if it contains a valid attribute format)
            if (! empty($product->aliexpress_sku)) {
                $sanitized = $this->sanitizeSkuAttr((string) $product->aliexpress_sku);
                if ($sanitized !== '') {
                    return $sanitized;
                }
            }

            // 3. Stored product_variants array
            $variants = is_array($product->product_variants)
                ? $product->product_variants
                : (json_decode($product->product_variants ?? '', true) ?: []);

            if (! empty($variants)) {
                $chosen = null;
                if ($overrideKey !== null) {
                    foreach ($variants as $v) {
                        if (
                            (isset($v['sku']) && (string) $v['sku'] === $overrideKey) ||
                            (isset($v['sku_id']) && (string) $v['sku_id'] === $overrideKey) ||
                            (isset($v['name']) && strcasecmp((string) $v['name'], $overrideKey) === 0)
                        ) {
                            $chosen = $v;
                            break;
                        }
                    }
                }

                if (! $chosen) {
                    foreach ($variants as $v) {
                        if (isset($v['stock']) && (int) $v['stock'] > 0) {
                            $chosen = $v;
                            break;
                        }
                    }
                }
                $chosen = $chosen ?? $variants[0];

                $attr = $this->extractSkuAttrFromVariant($chosen);
                if ($attr !== '') {
                    try {
                        $product->update(['aliexpress_sku' => $attr]);
                    } catch (\Throwable $e) {
                    }

                    return $attr;
                }
            }

            // 4. Stored aliexpress_source_data['skus']
            $sourceSkus = $product->aliexpress_source_data['skus'] ?? [];
            if (! empty($sourceSkus) && is_array($sourceSkus)) {
                $chosenSku = null;
                if ($overrideKey !== null) {
                    foreach ($sourceSkus as $s) {
                        if (
                            (isset($s['sku_id']) && (string) $s['sku_id'] === $overrideKey) ||
                            (isset($s['sku_code']) && (string) $s['sku_code'] === $overrideKey)
                        ) {
                            $chosenSku = $s;
                            break;
                        }
                    }
                }

                if (! $chosenSku) {
                    foreach ($sourceSkus as $s) {
                        if (isset($s['stock']) && (int) $s['stock'] > 0) {
                            $chosenSku = $s;
                            break;
                        }
                    }
                }
                $chosenSku = $chosenSku ?? $sourceSkus[0];

                $attr = $this->extractSkuAttrFromVariant($chosenSku);
                if ($attr !== '') {
                    try {
                        $product->update(['aliexpress_sku' => $attr]);
                    } catch (\Throwable $e) {
                    }

                    return $attr;
                }
            }
        }

        // 5. Query AliExpress API live to fetch current authentic SKU
        try {
            $effectiveCountry = 'US';
            $shippingAddress = is_array($order?->shipping_address)
                ? $order->shipping_address
                : ($options['shipping_address'] ?? (is_array($item->order?->shipping_address) ? $item->order->shipping_address : []));
            $country = strtoupper(trim((string) ($shippingAddress['country_code'] ?? $shippingAddress['country'] ?? 'US')));
            if (strlen($country) === 2) {
                $effectiveCountry = $country;
            }

            $productClient = app(AliExpressProductClient::class);
            $liveDto = $productClient->detail($account, $effectiveCountry, (string) $product->aliexpress_product_id, $forceRefresh, enrich: false);

            if ($liveDto && ! empty($liveDto->skus)) {
                $chosenSkuDto = null;
                if ($overrideKey !== null) {
                    foreach ($liveDto->skus as $sDto) {
                        if (
                            $sDto->skuId === $overrideKey ||
                            (string) $sDto->skuCode === $overrideKey
                        ) {
                            $chosenSkuDto = $sDto;
                            break;
                        }
                    }
                }

                if (! $chosenSkuDto) {
                    foreach ($liveDto->skus as $sDto) {
                        if ($sDto->stock > 0) {
                            $chosenSkuDto = $sDto;
                            break;
                        }
                    }
                }
                $chosenSkuDto = $chosenSkuDto ?? $liveDto->skus[0];

                // 5a. Build from SKU property IDs
                $propPairs = [];
                foreach ($chosenSkuDto->properties as $prop) {
                    if (! empty($prop['property_id']) && ! empty($prop['value_id'])) {
                        $propPairs[] = $prop['property_id'].':'.$prop['value_id'];
                    }
                }
                $resolvedAttr = ! empty($propPairs) ? implode(';', $propPairs) : '';

                // 5b. Fall back to sanitized raw skuAttr or skuCode
                if ($resolvedAttr === '') {
                    $resolvedAttr = $this->sanitizeSkuAttr($chosenSkuDto->skuAttr ?: (string) $chosenSkuDto->skuCode);
                }

                if ($resolvedAttr !== '') {
                    try {
                        $product->update(['aliexpress_sku' => $resolvedAttr]);
                    } catch (\Throwable $e) {
                    }

                    return $resolvedAttr;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Live SKU fetch fallback failed for product {$product->id}: ".$e->getMessage());
        }

        return '';
    }

    /**
     * Extracts a sanitized attribute string (property_id:value_id) from a variant array.
     */
    protected function extractSkuAttrFromVariant(array $v): string
    {
        // 1. Direct sku_attr
        if (! empty($v['sku_attr'])) {
            $sanitized = $this->sanitizeSkuAttr((string) $v['sku_attr']);
            if ($sanitized !== '') {
                return $sanitized;
            }
        }

        // 2. sku or sku_code if it holds property_id:value_id format
        foreach (['sku', 'sku_code'] as $field) {
            if (! empty($v[$field]) && str_contains((string) $v[$field], ':')) {
                $sanitized = $this->sanitizeSkuAttr((string) $v[$field]);
                if ($sanitized !== '') {
                    return $sanitized;
                }
            }
        }

        // 3. options / properties with property_id and value_id
        $propList = $v['options'] ?? $v['properties'] ?? [];
        if (is_array($propList) && ! empty($propList)) {
            $pairs = [];
            foreach ($propList as $prop) {
                if (is_array($prop) && ! empty($prop['property_id']) && ! empty($prop['value_id'])) {
                    $pairs[] = $prop['property_id'].':'.$prop['value_id'];
                }
            }
            if (! empty($pairs)) {
                return implode(';', $pairs);
            }
        }

        return '';
    }

    /**
     * Sanitize a SKU attribute string to ensure it contains only raw property_id:value_id pairs.
     * Strips hash labels (e.g. #White, #Size) and any extra symbols.
     * Returns empty string if no valid attribute pairs are present.
     */
    protected function sanitizeSkuAttr(string $attr): string
    {
        $clean = trim($attr);
        if ($clean === '') {
            return '';
        }

        // Remove hash labels: "14:70221#Red;5:100#M" -> "14:70221;5:100"
        $clean = preg_replace('/#[^;]*/', '', $clean);

        // Extract valid digits:digits pairs
        if (preg_match_all('/\b(\d+:\d+)\b/', $clean, $matches) && ! empty($matches[1])) {
            return implode(';', $matches[1]);
        }

        return '';
    }

    /**
     * Resolve a phone country dial code from the shipping address.
     * Falls back to +1 (US) when no country code is present.
     */
    private function resolvePhoneCountryCode(array $address): string
    {
        $country = strtoupper(trim($address['country_code'] ?? $address['country'] ?? ''));

        $dialCodes = [
            'US' => '+1',  'CA' => '+1',  'GB' => '+44', 'AU' => '+61',
            'DE' => '+49', 'FR' => '+33', 'IT' => '+39', 'ES' => '+34',
            'NL' => '+31', 'BE' => '+32', 'SE' => '+46', 'NO' => '+47',
            'DK' => '+45', 'FI' => '+358', 'PL' => '+48', 'PT' => '+351',
            'BR' => '+55', 'MX' => '+52', 'AR' => '+54', 'CL' => '+56',
            'JP' => '+81', 'KR' => '+82', 'CN' => '+86', 'IN' => '+91',
            'SG' => '+65', 'MY' => '+60', 'TH' => '+66', 'PH' => '+63',
            'ID' => '+62', 'VN' => '+84', 'AE' => '+971', 'SA' => '+966',
            'ZA' => '+27', 'NG' => '+234', 'EG' => '+20', 'KE' => '+254',
            'NZ' => '+64', 'RU' => '+7',  'TR' => '+90', 'UA' => '+380',
        ];

        return $dialCodes[$country] ?? '+1';
    }

    /**
     * Map of US 2-letter state code => Full State Name
     */
    protected static array $usStates = [
        'AL' => 'Alabama',                  'AK' => 'Alaska',
        'AZ' => 'Arizona',                  'AR' => 'Arkansas',
        'CA' => 'California',               'CO' => 'Colorado',
        'CT' => 'Connecticut',              'DE' => 'Delaware',
        'FL' => 'Florida',                 'GA' => 'Georgia',
        'HI' => 'Hawaii',                  'ID' => 'Idaho',
        'IL' => 'Illinois',                 'IN' => 'Indiana',
        'IA' => 'Iowa',                     'KS' => 'Kansas',
        'KY' => 'Kentucky',                 'LA' => 'Louisiana',
        'ME' => 'Maine',                    'MD' => 'Maryland',
        'MA' => 'Massachusetts',            'MI' => 'Michigan',
        'MN' => 'Minnesota',                'MS' => 'Mississippi',
        'MO' => 'Missouri',                 'MT' => 'Montana',
        'NE' => 'Nebraska',                 'NV' => 'Nevada',
        'NH' => 'New Hampshire',            'NJ' => 'New Jersey',
        'NM' => 'New Mexico',               'NY' => 'New York',
        'NC' => 'North Carolina',           'ND' => 'North Dakota',
        'OH' => 'Ohio',                     'OK' => 'Oklahoma',
        'OR' => 'Oregon',                   'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',             'SC' => 'South Carolina',
        'SD' => 'South Dakota',             'TN' => 'Tennessee',
        'TX' => 'Texas',                    'UT' => 'Utah',
        'VT' => 'Vermont',                  'VA' => 'Virginia',
        'WA' => 'Washington',               'WV' => 'West Virginia',
        'WI' => 'Wisconsin',                'WY' => 'Wyoming',
        'DC' => 'District of Columbia',     'PR' => 'Puerto Rico',
        'VI' => 'Virgin Islands',           'GU' => 'Guam',
        'MP' => 'Northern Mariana Islands', 'AS' => 'American Samoa',
    ];

    /**
     * Map of Canada 2-letter province code => Full Province Name
     */
    protected static array $caProvinces = [
        'AB' => 'Alberta',
        'BC' => 'British Columbia',
        'MB' => 'Manitoba',
        'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia',
        'NT' => 'Northwest Territories',
        'NU' => 'Nunavut',
        'ON' => 'Ontario',
        'PE' => 'Prince Edward Island',
        'QC' => 'Quebec',
        'SK' => 'Saskatchewan',
        'YT' => 'Yukon',
    ];

    /**
     * Map of Australia state code => Full State Name
     */
    protected static array $auStates = [
        'NSW' => 'New South Wales',
        'VIC' => 'Victoria',
        'QLD' => 'Queensland',
        'SA' => 'South Australia',
        'WA' => 'Western Australia',
        'TAS' => 'Tasmania',
        'ACT' => 'Australian Capital Territory',
        'NT' => 'Northern Territory',
    ];

    /**
     * UK Outward Postcode area prefix => Official AliExpress County/Region
     */
    protected static array $gbPostcodeCounties = [
        'E' => 'Greater London', 'EC' => 'Greater London', 'N' => 'Greater London',
        'NW' => 'Greater London', 'SE' => 'Greater London', 'SW' => 'Greater London',
        'W' => 'Greater London', 'WC' => 'Greater London', 'BR' => 'Greater London',
        'CR' => 'Greater London', 'DA' => 'Greater London', 'EN' => 'Greater London',
        'HA' => 'Greater London', 'IG' => 'Greater London', 'KT' => 'Greater London',
        'RM' => 'Greater London', 'SM' => 'Greater London', 'TW' => 'Greater London',
        'UB' => 'Greater London',
        'B' => 'West Midlands', 'CV' => 'West Midlands', 'DY' => 'West Midlands',
        'WS' => 'West Midlands', 'WV' => 'West Midlands',
        'M' => 'Greater Manchester', 'BL' => 'Greater Manchester', 'OL' => 'Greater Manchester',
        'SK' => 'Greater Manchester', 'WN' => 'Greater Manchester',
        'LS' => 'West Yorkshire', 'BD' => 'West Yorkshire', 'HD' => 'West Yorkshire',
        'HX' => 'West Yorkshire', 'WF' => 'West Yorkshire',
        'L' => 'Merseyside', 'WA' => 'Cheshire', 'CH' => 'Cheshire', 'CW' => 'Cheshire',
        'S' => 'South Yorkshire', 'DN' => 'South Yorkshire',
        'NE' => 'Tyne and Wear', 'SR' => 'Tyne and Wear', 'DH' => 'County Durham', 'DL' => 'County Durham',
        'TS' => 'North Yorkshire', 'HG' => 'North Yorkshire', 'YO' => 'North Yorkshire',
        'HU' => 'East Riding of Yorkshire',
        'PR' => 'Lancashire', 'BB' => 'Lancashire', 'FY' => 'Lancashire', 'LA' => 'Lancashire',
        'CA' => 'Cumbria',
        'DE' => 'Derbyshire', 'NG' => 'Nottinghamshire', 'LE' => 'Leicestershire',
        'LN' => 'Lincolnshire', 'NN' => 'Northamptonshire', 'ST' => 'Staffordshire',
        'WR' => 'Worcestershire', 'HR' => 'Herefordshire', 'TF' => 'Shropshire', 'SY' => 'Shropshire',
        'CB' => 'Cambridgeshire', 'PE' => 'Cambridgeshire',
        'NR' => 'Norfolk', 'IP' => 'Suffolk',
        'CM' => 'Essex', 'CO' => 'Essex', 'SS' => 'Essex',
        'AL' => 'Hertfordshire', 'SG' => 'Hertfordshire', 'WD' => 'Hertfordshire',
        'HP' => 'Buckinghamshire', 'MK' => 'Buckinghamshire', 'LU' => 'Bedfordshire',
        'OX' => 'Oxfordshire', 'RG' => 'Berkshire', 'SL' => 'Berkshire',
        'ME' => 'Kent', 'CT' => 'Kent', 'TN' => 'Kent',
        'BN' => 'East Sussex', 'RH' => 'West Sussex', 'GU' => 'Surrey',
        'SO' => 'Hampshire', 'PO' => 'Hampshire',
        'BH' => 'Dorset', 'DT' => 'Dorset',
        'SN' => 'Wiltshire', 'SP' => 'Wiltshire',
        'BS' => 'Bristol', 'BA' => 'Somerset', 'TA' => 'Somerset',
        'GL' => 'Gloucestershire',
        'EX' => 'Devon', 'PL' => 'Devon', 'TQ' => 'Devon',
        'TR' => 'Cornwall',
        'CF' => 'Cardiff', 'NP' => 'Newport', 'SA' => 'Swansea', 'LL' => 'Gwynedd', 'LD' => 'Powys',
        'G' => 'Glasgow City', 'PA' => 'Renfrewshire', 'ML' => 'North Lanarkshire',
        'EH' => 'City of Edinburgh', 'KY' => 'Fife', 'FK' => 'Falkirk',
        'DD' => 'Dundee City', 'AB' => 'Aberdeenshire', 'IV' => 'Highland', 'KW' => 'Highland', 'PH' => 'Perth and Kinross',
        'BT' => 'County Antrim',
    ];

    /**
     * Common UK cities/towns => Official AliExpress County/Region
     */
    protected static array $gbCityCounties = [
        'london' => 'Greater London',
        'manchester' => 'Greater Manchester',
        'birmingham' => 'West Midlands',
        'leeds' => 'West Yorkshire',
        'glasgow' => 'Glasgow City',
        'edinburgh' => 'City of Edinburgh',
        'liverpool' => 'Merseyside',
        'sheffield' => 'South Yorkshire',
        'bristol' => 'Bristol',
        'newcastle' => 'Tyne and Wear',
        'newcastle upon tyne' => 'Tyne and Wear',
        'nottingham' => 'Nottinghamshire',
        'cardiff' => 'Cardiff',
        'belfast' => 'County Antrim',
        'southampton' => 'Hampshire',
        'portsmouth' => 'Hampshire',
        'leicester' => 'Leicestershire',
        'coventry' => 'West Midlands',
        'hull' => 'East Riding of Yorkshire',
        'kingston upon hull' => 'East Riding of Yorkshire',
        'bradford' => 'West Yorkshire',
        'stoke-on-trent' => 'Staffordshire',
        'wolverhampton' => 'West Midlands',
        'plymouth' => 'Devon',
        'derby' => 'Derbyshire',
        'swansea' => 'Swansea',
        'milton keynes' => 'Buckinghamshire',
        'aberdeen' => 'Aberdeen City',
        'norwich' => 'Norfolk',
        'luton' => 'Bedfordshire',
        'swindon' => 'Wiltshire',
        'northampton' => 'Northamptonshire',
        'reading' => 'Berkshire',
        'dudley' => 'West Midlands',
        'walsall' => 'West Midlands',
        'solihull' => 'West Midlands',
        'bournemouth' => 'Dorset',
        'sunderland' => 'Tyne and Wear',
        'peterborough' => 'Cambridgeshire',
        'brighton' => 'East Sussex',
        'oxford' => 'Oxfordshire',
        'cambridge' => 'Cambridgeshire',
        'york' => 'North Yorkshire',
        'exeter' => 'Devon',
        'gloucester' => 'Gloucestershire',
        'cheltenham' => 'Gloucestershire',
        'ipswich' => 'Suffolk',
        'colchester' => 'Essex',
        'chelmsford' => 'Essex',
        'salford' => 'Greater Manchester',
        'bolton' => 'Greater Manchester',
        'stockport' => 'Greater Manchester',
        'oldham' => 'Greater Manchester',
        'rochdale' => 'Greater Manchester',
        'wigan' => 'Greater Manchester',
        'huddersfield' => 'West Yorkshire',
        'halifax' => 'West Yorkshire',
        'wakefield' => 'West Yorkshire',
        'middlesbrough' => 'North Yorkshire',
        'blackpool' => 'Lancashire',
        'preston' => 'Lancashire',
        'warrington' => 'Cheshire',
        'chester' => 'Cheshire',
        'guildford' => 'Surrey',
        'watford' => 'Hertfordshire',
        'slough' => 'Berkshire',
        'crawley' => 'West Sussex',
        'worthing' => 'West Sussex',
        'eastbourne' => 'East Sussex',
        'hastings' => 'East Sussex',
        'basingstoke' => 'Hampshire',
        'winchester' => 'Hampshire',
        'bedford' => 'Bedfordshire',
        'worcester' => 'Worcestershire',
        'carlisle' => 'Cumbria',
        'lincoln' => 'Lincolnshire',
        'dundee' => 'Dundee City',
        'inverness' => 'Highland',
        'paisley' => 'Renfrewshire',
        'newport' => 'Newport',
        'wrexham' => 'Wrexham',
        'derry' => 'County Londonderry',
        'londonderry' => 'County Londonderry',
    ];

    /**
     * Resolves US 2-letter state code from a 5-digit US ZIP code.
     */
    protected function getStateFromUsZip(string $zip): ?string
    {
        $digits = preg_replace('/\D/', '', $zip);
        if (strlen($digits) < 5) {
            return null;
        }

        $code = (int) substr($digits, 0, 5);

        return match (true) {
            ($code >= 600 && $code <= 799) || ($code >= 900 && $code <= 999) => 'PR',
            $code >= 800 && $code <= 899 => 'VI',
            $code >= 1000 && $code <= 2799 => 'MA',
            $code >= 2800 && $code <= 2999 => 'RI',
            $code >= 3000 && $code <= 3899 => 'NH',
            $code >= 3900 && $code <= 4999 => 'ME',
            $code >= 5000 && $code <= 5999 => 'VT',
            $code >= 6000 && $code <= 6999 => 'CT',
            $code >= 7000 && $code <= 8999 => 'NJ',
            $code >= 10000 && $code <= 14999 => 'NY',
            $code >= 15000 && $code <= 19699 => 'PA',
            $code >= 19700 && $code <= 19999 => 'DE',
            ($code >= 20000 && $code <= 20099) || ($code >= 20200 && $code <= 20599) => 'DC',
            ($code >= 20100 && $code <= 20199) || ($code >= 22000 && $code <= 24699) => 'VA',
            $code >= 20600 && $code <= 21999 => 'MD',
            $code >= 24700 && $code <= 26999 => 'WV',
            $code >= 27000 && $code <= 28999 => 'NC',
            $code >= 29000 && $code <= 29999 => 'SC',
            ($code >= 30000 && $code <= 31999) || ($code >= 39800 && $code <= 39999) => 'GA',
            $code >= 32000 && $code <= 34999 => 'FL',
            $code >= 35000 && $code <= 36999 => 'AL',
            $code >= 37000 && $code <= 38599 => 'TN',
            $code >= 38600 && $code <= 39799 => 'MS',
            $code >= 40000 && $code <= 42799 => 'KY',
            $code >= 43000 && $code <= 45999 => 'OH',
            $code >= 46000 && $code <= 47999 => 'IN',
            $code >= 48000 && $code <= 49999 => 'MI',
            $code >= 50000 && $code <= 52999 => 'IA',
            $code >= 53000 && $code <= 54999 => 'WI',
            $code >= 55000 && $code <= 56799 => 'MN',
            $code >= 57000 && $code <= 57799 => 'SD',
            $code >= 58000 && $code <= 58899 => 'ND',
            $code >= 59000 && $code <= 59999 => 'MT',
            $code >= 60000 && $code <= 62999 => 'IL',
            $code >= 63000 && $code <= 65999 => 'MO',
            $code >= 66000 && $code <= 67999 => 'KS',
            $code >= 68000 && $code <= 69999 => 'NE',
            $code >= 70000 && $code <= 71599 => 'LA',
            ($code >= 71600 && $code <= 72999) || $code === 75502 => 'AR',
            $code >= 73000 && $code <= 74999 => 'OK',
            ($code >= 75000 && $code <= 79999) || ($code >= 88500 && $code <= 88599) => 'TX',
            $code >= 80000 && $code <= 81699 => 'CO',
            $code >= 82000 && $code <= 83199 => 'WY',
            $code >= 83200 && $code <= 83999 => 'ID',
            $code >= 84000 && $code <= 84799 => 'UT',
            $code >= 85000 && $code <= 86999 => 'AZ',
            $code >= 87000 && $code <= 88499 => 'NM',
            $code >= 88900 && $code <= 89899 => 'NV',
            $code >= 90000 && $code <= 96199 => 'CA',
            $code >= 96700 && $code <= 96899 => 'HI',
            $code >= 96900 && $code <= 96999 => 'GU',
            $code >= 97000 && $code <= 97999 => 'OR',
            $code >= 98000 && $code <= 99499 => 'WA',
            $code >= 99500 && $code <= 99999 => 'AK',
            default => null,
        };
    }

    /**
     * Intelligently resolves a valid State/Province/County recognized by AliExpress.
     * Prevents [B_DROPSHIPPER_DELIVERY_ADDRESS_VALIDATE_FAIL] Please select a State/Province/County.
     */
    public function resolveStateProvince(string $countryCode, array $address, bool $preferAbbreviation = false): string
    {
        $raw = trim((string) (
            $address['state']
            ?? $address['province']
            ?? $address['county']
            ?? $address['region']
            ?? ''
        ));
        $city = trim((string) ($address['city'] ?? $address['town'] ?? $address['district'] ?? ''));
        $zip = trim((string) ($address['postal_code'] ?? $address['zip'] ?? $address['postcode'] ?? ''));

        // If raw is a placeholder or generic string, treat as empty
        if (in_array(strtolower($raw), ['other', 'city', 'n/a', 'none', '-', 'null'], true)) {
            $raw = '';
        }

        // ==================== 1. UNITED STATES (US) ====================
        if ($countryCode === 'US') {
            // Direct state match
            if ($raw !== '') {
                $upper = strtoupper($raw);
                if (isset(self::$usStates[$upper])) {
                    return $preferAbbreviation ? $upper : self::$usStates[$upper];
                }
                foreach (self::$usStates as $abbr => $name) {
                    if (strcasecmp($raw, $name) === 0) {
                        return $preferAbbreviation ? $abbr : $name;
                    }
                }
            }

            // Deduce from ZIP
            $zipAbbr = $this->getStateFromUsZip($zip);
            if ($zipAbbr && isset(self::$usStates[$zipAbbr])) {
                return $preferAbbreviation ? $zipAbbr : self::$usStates[$zipAbbr];
            }

            // Deduce from City
            $cityMap = [
                'new york' => 'NY', 'nyc' => 'NY', 'brooklyn' => 'NY', 'queens' => 'NY', 'bronx' => 'NY',
                'los angeles' => 'CA', 'san francisco' => 'CA', 'san diego' => 'CA', 'san jose' => 'CA', 'sacramento' => 'CA',
                'chicago' => 'IL', 'houston' => 'TX', 'dallas' => 'TX', 'austin' => 'TX', 'san antonio' => 'TX', 'fort worth' => 'TX',
                'miami' => 'FL', 'orlando' => 'FL', 'tampa' => 'FL', 'jacksonville' => 'FL',
                'atlanta' => 'GA', 'seattle' => 'WA', 'boston' => 'MA', 'phoenix' => 'AZ', 'denver' => 'CO', 'las vegas' => 'NV',
                'philadelphia' => 'PA', 'pittsburgh' => 'PA', 'detroit' => 'MI', 'minneapolis' => 'MN',
                'charlotte' => 'NC', 'raleigh' => 'NC', 'nashville' => 'TN', 'memphis' => 'TN',
                'indianapolis' => 'IN', 'columbus' => 'OH', 'cleveland' => 'OH', 'cincinnati' => 'OH',
                'portland' => 'OR', 'new orleans' => 'LA', 'baltimore' => 'MD', 'salt lake city' => 'UT', 'honolulu' => 'HI',
                'washington' => 'DC',
            ];
            // Check if user entered a US state name or code in city field
            if ($raw === '' && $city !== '') {
                $upperCity = strtoupper($city);
                if (isset(self::$usStates[$upperCity])) {
                    return $preferAbbreviation ? $upperCity : self::$usStates[$upperCity];
                }
                foreach (self::$usStates as $abbr => $name) {
                    if (strcasecmp($city, $name) === 0) {
                        return $preferAbbreviation ? $abbr : $name;
                    }
                }
            }

            return $raw !== '' ? $raw : ($preferAbbreviation ? 'CA' : 'California');
        }

        // ==================== 2. UNITED KINGDOM (GB) ====================
        if ($countryCode === 'GB' || $countryCode === 'UK') {
            // AliExpress rejects UK counties/regions (e.g. "Greater London") with [B_DROPSHIPPER_DELIVERY_ADDRESS_VALIDATE_FAIL].
            // For the United Kingdom, province/state must be empty string.
            return '';
        }

        // ==================== 3. CANADA (CA) ====================
        if ($countryCode === 'CA') {
            if ($raw !== '') {
                $upper = strtoupper($raw);
                if (isset(self::$caProvinces[$upper])) {
                    return $preferAbbreviation ? $upper : self::$caProvinces[$upper];
                }
                foreach (self::$caProvinces as $abbr => $name) {
                    if (strcasecmp($raw, $name) === 0) {
                        return $preferAbbreviation ? $abbr : $name;
                    }
                }
            }

            // Deduce from postal code first letter
            if (preg_match('/^[A-Za-z]/', $zip, $m)) {
                $letter = strtoupper($m[0]);
                $caLetterMap = [
                    'A' => 'NL', 'B' => 'NS', 'C' => 'PE', 'E' => 'NB',
                    'G' => 'QC', 'H' => 'QC', 'J' => 'QC',
                    'K' => 'ON', 'L' => 'ON', 'M' => 'ON', 'N' => 'ON', 'P' => 'ON',
                    'R' => 'MB', 'S' => 'SK', 'T' => 'AB', 'V' => 'BC',
                    'X' => 'NT', 'Y' => 'YT',
                ];
                if (isset($caLetterMap[$letter])) {
                    $pAbbr = $caLetterMap[$letter];

                    return $preferAbbreviation ? $pAbbr : self::$caProvinces[$pAbbr];
                }
            }

            // Deduce from city
            $caCityMap = [
                'toronto' => 'ON', 'ottawa' => 'ON', 'mississauga' => 'ON', 'hamilton' => 'ON',
                'montreal' => 'QC', 'quebec' => 'QC', 'quebec city' => 'QC',
                'vancouver' => 'BC', 'victoria' => 'BC', 'surrey' => 'BC',
                'calgary' => 'AB', 'edmonton' => 'AB',
                'winnipeg' => 'MB', 'halifax' => 'NS',
            ];
            $lowerCity = strtolower($city);
            if (isset($caCityMap[$lowerCity])) {
                $pAbbr = $caCityMap[$lowerCity];

                return $preferAbbreviation ? $pAbbr : self::$caProvinces[$pAbbr];
            }

            return $raw !== '' ? $raw : ($preferAbbreviation ? 'ON' : 'Ontario');
        }

        // ==================== 4. AUSTRALIA (AU) ====================
        if ($countryCode === 'AU') {
            if ($raw !== '') {
                $upper = strtoupper($raw);
                if (isset(self::$auStates[$upper])) {
                    return $preferAbbreviation ? $upper : self::$auStates[$upper];
                }
                foreach (self::$auStates as $abbr => $name) {
                    if (strcasecmp($raw, $name) === 0) {
                        return $preferAbbreviation ? $abbr : $name;
                    }
                }
            }

            // Deduce from 4-digit postal code
            $auCode = (int) substr(preg_replace('/\D/', '', $zip), 0, 4);
            if ($auCode > 0) {
                $auAbbr = match (true) {
                    ($auCode >= 1000 && $auCode <= 2599) || ($auCode >= 2619 && $auCode <= 2899) || ($auCode >= 2921 && $auCode <= 2999) => 'NSW',
                    ($auCode >= 200 && $auCode <= 299) || ($auCode >= 2600 && $auCode <= 2618) || ($auCode >= 2900 && $auCode <= 2920) => 'ACT',
                    ($auCode >= 3000 && $auCode <= 3999) || ($auCode >= 8000 && $auCode <= 8999) => 'VIC',
                    ($auCode >= 4000 && $auCode <= 4999) || ($auCode >= 9000 && $auCode <= 9999) => 'QLD',
                    ($auCode >= 5000 && $auCode <= 5799) || ($auCode >= 5800 && $auCode <= 5999) => 'SA',
                    ($auCode >= 6000 && $auCode <= 6797) || ($auCode >= 6800 && $auCode <= 6999) => 'WA',
                    ($auCode >= 7000 && $auCode <= 7799) || ($auCode >= 7800 && $auCode <= 7999) => 'TAS',
                    ($auCode >= 800 && $auCode <= 899) || ($auCode >= 900 && $auCode <= 999) => 'NT',
                    default => null,
                };
                if ($auAbbr && isset(self::$auStates[$auAbbr])) {
                    return $preferAbbreviation ? $auAbbr : self::$auStates[$auAbbr];
                }
            }

            // Deduce from city
            $auCityMap = [
                'sydney' => 'NSW', 'melbourne' => 'VIC', 'brisbane' => 'QLD',
                'perth' => 'WA', 'adelaide' => 'SA', 'hobart' => 'TAS',
                'canberra' => 'ACT', 'darwin' => 'NT', 'gold coast' => 'QLD',
            ];
            $lowerCity = strtolower($city);
            if (isset($auCityMap[$lowerCity])) {
                $aAbbr = $auCityMap[$lowerCity];

                return $preferAbbreviation ? $aAbbr : self::$auStates[$aAbbr];
            }

            return $raw !== '' ? $raw : ($preferAbbreviation ? 'NSW' : 'New South Wales');
        }

        // ==================== 5. OTHER COUNTRIES ====================
        if ($raw !== '') {
            return $raw;
        }

        if ($city !== '') {
            return $city;
        }

        // Return empty string for unknown countries — do not fabricate a US state
        return '';
    }

    /**
     * Intelligently resolves a valid City recognized by AliExpress.
     * Prevents [B_DROPSHIPPER_DELIVERY_ADDRESS_VALIDATE_FAIL] Please enter a City.
     */
    public function resolveCity(string $countryCode, array $address, string $resolvedProvince): string
    {
        $rawCity = trim((string) (
            $address['city']
            ?? $address['town']
            ?? $address['district']
            ?? ''
        ));
        $zip = trim((string) ($address['postal_code'] ?? $address['zip'] ?? $address['postcode'] ?? ''));

        // If rawCity is a placeholder, a county/province name, or invalid, discard it
        $invalidCities = [
            'other', 'city', 'n/a', 'none', '-', 'null', 'unknown',
            'greater london', 'west midlands', 'greater manchester', 'west yorkshire',
            'south yorkshire', 'merseyside', 'tyne and wear',
        ];
        if (in_array(strtolower($rawCity), $invalidCities, true) || strcasecmp($rawCity, $resolvedProvince) === 0) {
            $rawCity = '';
        }

        // If rawCity is actually a US state name or code, discard rawCity so city deduces to a real city in that state
        if ($countryCode === 'US' && $rawCity !== '') {
            $upperCity = strtoupper($rawCity);
            if (isset(self::$usStates[$upperCity])) {
                $rawCity = '';
                $resolvedProvince = self::$usStates[$upperCity];
            } else {
                foreach (self::$usStates as $abbr => $name) {
                    if (strcasecmp($rawCity, $name) === 0) {
                        $rawCity = '';
                        $resolvedProvince = $name;
                        break;
                    }
                }
            }
        }

        // 1. UNITED KINGDOM (GB)
        if ($countryCode === 'GB' || $countryCode === 'UK') {
            if ($rawCity !== '') {
                return $rawCity;
            }

            // Deduce from outward postcode
            if (preg_match('/^([A-Za-z]{1,2})\d/i', $zip, $m)) {
                $postcodeArea = strtoupper($m[1]);
                $gbPostcodeCities = [
                    'E' => 'London', 'EC' => 'London', 'N' => 'London', 'NW' => 'London',
                    'SE' => 'London', 'SW' => 'London', 'W' => 'London', 'WC' => 'London',
                    'BR' => 'London', 'CR' => 'London', 'DA' => 'London', 'EN' => 'London',
                    'HA' => 'London', 'IG' => 'London', 'KT' => 'London', 'RM' => 'London',
                    'SM' => 'London', 'TW' => 'London', 'UB' => 'London',
                    'B' => 'Birmingham', 'CV' => 'Coventry', 'DY' => 'Dudley',
                    'WS' => 'Walsall', 'WV' => 'Wolverhampton',
                    'M' => 'Manchester', 'BL' => 'Bolton', 'OL' => 'Oldham',
                    'SK' => 'Stockport', 'WN' => 'Wigan',
                    'LS' => 'Leeds', 'BD' => 'Bradford', 'HD' => 'Huddersfield',
                    'HX' => 'Halifax', 'WF' => 'Wakefield',
                    'L' => 'Liverpool', 'WA' => 'Warrington', 'CH' => 'Chester',
                    'S' => 'Sheffield', 'DN' => 'Doncaster',
                    'NE' => 'Newcastle upon Tyne', 'SR' => 'Sunderland',
                    'TS' => 'Middlesbrough', 'HG' => 'Harrogate', 'YO' => 'York',
                    'HU' => 'Hull', 'PR' => 'Preston', 'BB' => 'Blackburn',
                    'FY' => 'Blackpool', 'LA' => 'Lancaster', 'CA' => 'Carlisle',
                    'DE' => 'Derby', 'NG' => 'Nottingham', 'LE' => 'Leicester',
                    'LN' => 'Lincoln', 'NN' => 'Northampton', 'ST' => 'Stoke-on-Trent',
                    'WR' => 'Worcester', 'HR' => 'Hereford', 'TF' => 'Telford', 'SY' => 'Shrewsbury',
                    'CB' => 'Cambridge', 'PE' => 'Peterborough', 'NR' => 'Norwich', 'IP' => 'Ipswich',
                    'CM' => 'Chelmsford', 'CO' => 'Colchester', 'SS' => 'Southend-on-Sea',
                    'AL' => 'St Albans', 'SG' => 'Stevenage', 'WD' => 'Watford',
                    'HP' => 'Aylesbury', 'MK' => 'Milton Keynes', 'LU' => 'Luton',
                    'OX' => 'Oxford', 'RG' => 'Reading', 'SL' => 'Slough',
                    'ME' => 'Maidstone', 'CT' => 'Canterbury', 'TN' => 'Tunbridge Wells',
                    'BN' => 'Brighton', 'RH' => 'Crawley', 'GU' => 'Guildford',
                    'SO' => 'Southampton', 'PO' => 'Portsmouth',
                    'BH' => 'Bournemouth', 'DT' => 'Dorchester',
                    'SN' => 'Swindon', 'SP' => 'Salisbury',
                    'BS' => 'Bristol', 'BA' => 'Bath', 'TA' => 'Taunton',
                    'GL' => 'Gloucester', 'EX' => 'Exeter', 'PL' => 'Plymouth', 'TQ' => 'Torquay',
                    'TR' => 'Truro', 'CF' => 'Cardiff', 'NP' => 'Newport', 'SA' => 'Swansea',
                    'G' => 'Glasgow', 'EH' => 'Edinburgh', 'AB' => 'Aberdeen', 'DD' => 'Dundee',
                    'BT' => 'Belfast',
                ];
                if (isset($gbPostcodeCities[$postcodeArea])) {
                    return $gbPostcodeCities[$postcodeArea];
                }
            }

            // Deduce from resolved county
            $countyCityMap = [
                'Greater London' => 'London',
                'West Midlands' => 'Birmingham',
                'Greater Manchester' => 'Manchester',
                'West Yorkshire' => 'Leeds',
                'Merseyside' => 'Liverpool',
                'South Yorkshire' => 'Sheffield',
                'Tyne and Wear' => 'Newcastle upon Tyne',
                'Bristol' => 'Bristol',
                'Glasgow City' => 'Glasgow',
                'City of Edinburgh' => 'Edinburgh',
                'Cardiff' => 'Cardiff',
                'County Antrim' => 'Belfast',
                'Leicestershire' => 'Leicester',
                'Nottinghamshire' => 'Nottingham',
                'Hampshire' => 'Southampton',
                'East Sussex' => 'Brighton',
                'Berkshire' => 'Reading',
                'Northamptonshire' => 'Northampton',
                'Buckinghamshire' => 'Milton Keynes',
                'Norfolk' => 'Norwich',
                'Cambridgeshire' => 'Cambridge',
                'Oxfordshire' => 'Oxford',
                'Devon' => 'Exeter',
                'Somerset' => 'Bath',
                'Gloucestershire' => 'Gloucester',
                'Lancashire' => 'Preston',
                'Cheshire' => 'Chester',
                'Hertfordshire' => 'Watford',
                'Surrey' => 'Guildford',
                'Kent' => 'Canterbury',
                'Essex' => 'Chelmsford',
                'Derbyshire' => 'Derby',
                'Staffordshire' => 'Stoke-on-Trent',
                'Warwickshire' => 'Coventry',
                'Dorset' => 'Bournemouth',
                'Wiltshire' => 'Swindon',
                'Suffolk' => 'Ipswich',
                'Bedfordshire' => 'Luton',
                'Worcestershire' => 'Worcester',
                'Cumbria' => 'Carlisle',
                'Lincolnshire' => 'Lincoln',
                'East Riding of Yorkshire' => 'Hull',
            ];
            if (isset($countyCityMap[$resolvedProvince])) {
                return $countyCityMap[$resolvedProvince];
            }

            return 'London';
        }

        // 2. UNITED STATES (US)
        if ($countryCode === 'US') {
            if ($rawCity !== '') {
                return $rawCity;
            }

            // Deduce from ZIP range
            $digits = preg_replace('/\D/', '', $zip);
            $zip5 = strlen($digits) >= 5 ? (int) substr($digits, 0, 5) : 0;
            if ($zip5 > 0) {
                if ($zip5 >= 75000 && $zip5 <= 75499) {
                    return 'Dallas';
                }
                if ($zip5 >= 77000 && $zip5 <= 77299) {
                    return 'Houston';
                }
                if ($zip5 >= 78200 && $zip5 <= 78299) {
                    return 'San Antonio';
                }
                if ($zip5 >= 78700 && $zip5 <= 78799) {
                    return 'Austin';
                }
                if ($zip5 >= 75000 && $zip5 <= 79999) {
                    return 'Dallas';
                }
                if ($zip5 >= 90000 && $zip5 <= 90099) {
                    return 'Los Angeles';
                }
                if ($zip5 >= 94100 && $zip5 <= 94199) {
                    return 'San Francisco';
                }
                if ($zip5 >= 92100 && $zip5 <= 92199) {
                    return 'San Diego';
                }
                if ($zip5 >= 95100 && $zip5 <= 95199) {
                    return 'San Jose';
                }
                if ($zip5 >= 90000 && $zip5 <= 96199) {
                    return 'Los Angeles';
                }
                if ($zip5 >= 10000 && $zip5 <= 10299) {
                    return 'New York';
                }
                if ($zip5 >= 11200 && $zip5 <= 11299) {
                    return 'Brooklyn';
                }
                if ($zip5 >= 10000 && $zip5 <= 14999) {
                    return 'New York';
                }
                if ($zip5 >= 60600 && $zip5 <= 60699) {
                    return 'Chicago';
                }
                if ($zip5 >= 60000 && $zip5 <= 62999) {
                    return 'Chicago';
                }
                if ($zip5 >= 33100 && $zip5 <= 33299) {
                    return 'Miami';
                }
                if ($zip5 >= 32000 && $zip5 <= 34999) {
                    return 'Miami';
                }
                if ($zip5 >= 30300 && $zip5 <= 30399) {
                    return 'Atlanta';
                }
                if ($zip5 >= 30000 && $zip5 <= 31999) {
                    return 'Atlanta';
                }
                if ($zip5 >= 98100 && $zip5 <= 98199) {
                    return 'Seattle';
                }
                if ($zip5 >= 98000 && $zip5 <= 99499) {
                    return 'Seattle';
                }
                if ($zip5 >= 2100 && $zip5 <= 2299) {
                    return 'Boston';
                }
                if ($zip5 >= 1000 && $zip5 <= 2799) {
                    return 'Boston';
                }
                if ($zip5 >= 85000 && $zip5 <= 85099) {
                    return 'Phoenix';
                }
                if ($zip5 >= 80200 && $zip5 <= 80299) {
                    return 'Denver';
                }
                if ($zip5 >= 89100 && $zip5 <= 89199) {
                    return 'Las Vegas';
                }
                if ($zip5 >= 19100 && $zip5 <= 19199) {
                    return 'Philadelphia';
                }
                if ($zip5 >= 48200 && $zip5 <= 48299) {
                    return 'Detroit';
                }
                if ($zip5 >= 55400 && $zip5 <= 55499) {
                    return 'Minneapolis';
                }
                if ($zip5 >= 28200 && $zip5 <= 28299) {
                    return 'Charlotte';
                }
                if ($zip5 >= 37200 && $zip5 <= 37299) {
                    return 'Nashville';
                }
                if ($zip5 >= 46200 && $zip5 <= 46299) {
                    return 'Indianapolis';
                }
                if ($zip5 >= 43200 && $zip5 <= 43299) {
                    return 'Columbus';
                }
                if ($zip5 >= 97200 && $zip5 <= 97299) {
                    return 'Portland';
                }
                if ($zip5 >= 70100 && $zip5 <= 70199) {
                    return 'New Orleans';
                }
                if ($zip5 >= 21200 && $zip5 <= 21299) {
                    return 'Baltimore';
                }
                if ($zip5 >= 84100 && $zip5 <= 84199) {
                    return 'Salt Lake City';
                }
                if ($zip5 >= 96800 && $zip5 <= 96899) {
                    return 'Honolulu';
                }
                if ($zip5 >= 20000 && $zip5 <= 20099) {
                    return 'Washington';
                }
            }

            // Deduce from resolved state
            $stateCityMap = [
                'Texas' => 'Dallas', 'California' => 'Los Angeles', 'New York' => 'New York',
                'Florida' => 'Miami', 'Illinois' => 'Chicago', 'Georgia' => 'Atlanta',
                'Washington' => 'Seattle', 'Massachusetts' => 'Boston', 'Colorado' => 'Denver',
                'Arizona' => 'Phoenix', 'Nevada' => 'Las Vegas', 'Pennsylvania' => 'Philadelphia',
                'Michigan' => 'Detroit', 'Minnesota' => 'Minneapolis', 'North Carolina' => 'Charlotte',
                'Tennessee' => 'Nashville', 'Indiana' => 'Indianapolis', 'Ohio' => 'Columbus',
                'Oregon' => 'Portland', 'Louisiana' => 'New Orleans', 'Maryland' => 'Baltimore',
                'Utah' => 'Salt Lake City', 'Hawaii' => 'Honolulu', 'District of Columbia' => 'Washington',
            ];
            if (isset($stateCityMap[$resolvedProvince])) {
                return $stateCityMap[$resolvedProvince];
            }

            return 'New York';
        }

        // 3. CANADA (CA)
        if ($countryCode === 'CA') {
            if ($rawCity !== '') {
                return $rawCity;
            }
            $caCityMap = [
                'Ontario' => 'Toronto', 'Quebec' => 'Montreal', 'British Columbia' => 'Vancouver',
                'Alberta' => 'Calgary', 'Manitoba' => 'Winnipeg', 'Nova Scotia' => 'Halifax',
                'Saskatchewan' => 'Saskatoon', 'New Brunswick' => 'Moncton',
            ];

            return $caCityMap[$resolvedProvince] ?? 'Toronto';
        }

        // 4. AUSTRALIA (AU)
        if ($countryCode === 'AU') {
            if ($rawCity !== '') {
                return $rawCity;
            }
            $auCityMap = [
                'New South Wales' => 'Sydney', 'Victoria' => 'Melbourne', 'Queensland' => 'Brisbane',
                'Western Australia' => 'Perth', 'South Australia' => 'Adelaide', 'Tasmania' => 'Hobart',
                'Australian Capital Territory' => 'Canberra', 'Northern Territory' => 'Darwin',
            ];

            return $auCityMap[$resolvedProvince] ?? 'Sydney';
        }

        // 5. OTHER COUNTRIES
        if ($rawCity !== '') {
            return $rawCity;
        }

        return 'City';
    }

    /**
     * Build and validate the AliExpress logistics address DTO.
     * Maps stored order shipping address (line1, city, state, postal_code, country)
     * to AliExpress MaillingAddressRequestDto fields.
     */
    public function buildLogisticsAddress(Order $order, array $addressOverride = [], bool $preferStateAbbr = false): array
    {
        return app(FormatAliExpressAddressAction::class)->forOrder($order, $addressOverride, $preferStateAbbr);
    }

    /**
     * Retrieves tracking info from AE API.
     * Maps to aliexpress.trade.ds.order.get
     */
    public function getOrderTracking(AliExpressAccount $account, string $aeOrderId): array
    {
        $method = config('services.aliexpress.methods.order_get', 'aliexpress.trade.ds.order.get');

        $params = [
            'order_id' => $aeOrderId,
        ];

        $response = $this->call($account, $method, $params);
        $result = $response['result'] ?? $response['resp_result']['result'] ?? $response;

        return [
            'status' => $result['order_status'] ?? '', // e.g. WAIT_SELLER_SEND_GOODS, SELLER_PART_SEND_GOODS, WAIT_BUYER_ACCEPT_GOODS, FINISH
            'tracking_number' => $result['logistics_info'][0]['logistics_no'] ?? null,
        ];
    }

    protected function call(AliExpressAccount $account, string $method, array $params): array
    {
        $token = $this->tokens->ensureValidToken($account);

        return $this->client->call(
            $method,
            $params,
            $token,
            $account->region,
            $account->id,
            false
        );
    }

    /**
     * Call with flatParams=true — each $params key is sent as a top-level
     * POST field alongside system params instead of being wrapped in param_json.
     * Required by aliexpress.ds.order.create.
     */
    protected function callFlat(AliExpressAccount $account, string $method, array $params): array
    {
        $token = $this->tokens->ensureValidToken($account);

        return $this->client->call(
            $method,
            $params,
            $token,
            $account->region,
            $account->id,
            true
        );
    }
}
