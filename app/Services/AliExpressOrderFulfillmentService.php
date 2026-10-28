<?php

namespace App\Services;

use App\Actions\FormatAliExpressAddressAction;
use App\Models\AliExpressAccount;
use App\Models\Order;
use Exception;
use Throwable;

class AliExpressOrderFulfillmentService
{
    public function __construct(
        protected AliExpressOrderClient $client,
        protected FormatAliExpressAddressAction $addressFormatter
    ) {}

    /**
     * Pushes the Castillo order to the AliExpress Dropshipping API.
     *
     * @return array{payment_url: string, payment_urls: array, aliexpress_order_numbers: array, already_submitted: bool}
     *
     * @throws Throwable
     */
    public function execute(Order $order, AliExpressAccount $account, array $logisticsOptions = []): array
    {
        // Validate that the order contains an AliExpress product
        if (! $order->isAliExpressFulfilled()) {
            throw new Exception('Order does not contain any AliExpress products.');
        }

        // If the order has already been submitted to supplier
        if ($order->hasPurchaseRecord()) {
            $existingNumbers = (array) ($order->aliexpress_order_numbers ?? []);
            $firstOrderId = ! empty($existingNumbers) ? (string) reset($existingNumbers) : '';

            // If we have an existing order number, return its payment link directly
            if ($firstOrderId !== '') {
                return [
                    'payment_url' => $this->client->buildPaymentUrl($firstOrderId, $existingNumbers),
                    'payment_urls' => $this->client->buildPaymentUrls($firstOrderId, $existingNumbers),
                    'aliexpress_order_numbers' => $existingNumbers,
                    'already_submitted' => true,
                ];
            }

            // If purchased_at was set previously without valid order numbers (due to an empty-URL bug),
            // self-heal the order state so it can be re-pushed cleanly to AliExpress.
            $order->update([
                'purchased_at' => null,
                'aliexpress_placed_at' => null,
                'aliexpress_order_numbers' => null,
            ]);
        }

        // 1. Sanitize and format the delivery address payload before pushing to AliExpress
        $shippingOverride = $logisticsOptions['shipping_address'] ?? [];
        $sanitizedDto = $this->addressFormatter->forOrder($order, $shippingOverride);

        // Update the order's stored shipping address with the sanitized fields
        $currentAddress = is_array($order->shipping_address) ? $order->shipping_address : [];
        $order->update([
            'shipping_address' => array_merge($currentAddress, $sanitizedDto),
        ]);

        $logisticsOptions['shipping_address'] = $sanitizedDto;

        // Push data to AliExpress Dropshipping API OUTSIDE any database transaction.
        // Holding transactions open across outbound network calls causes long-lived InnoDB locks
        // on the cache and order tables, resulting in 1205 lock wait timeouts on other endpoints.
        $response = $this->client->createOrder($account, $order, $logisticsOptions);

        $orderList = ! empty($response['order_list'])
            ? array_map('strval', (array) $response['order_list'])
            : array_filter([(string) $response['order_id']]);

        // Save the AliExpress order details on our Order model
        $order->update([
            'status' => 'processing',
            'aliexpress_order_numbers' => $orderList,
            'aliexpress_placed_at' => now(),
            'purchased_at' => now(),
            'supplier_name' => 'AliExpress',
        ]);

        return [
            'payment_url' => $response['payment_url'],
            'payment_urls' => $response['payment_urls'] ?? $this->client->buildPaymentUrls((string) $response['order_id'], $orderList),
            'aliexpress_order_numbers' => $orderList,
            'already_submitted' => false,
        ];
    }
}
