<?php

namespace App\Http\Controllers;

use App\Http\Requests\FulfillAliExpressOrderRequest;
use App\Models\AliExpressAccount;
use App\Models\Order;
use App\Services\AliExpressOrderClient;
use App\Services\AliExpressOrderFulfillmentService;
use App\Support\ApiResponse;
use App\Traits\ResolvesAuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class AliExpressFulfillmentController extends Controller
{
    use ResolvesAuthUser;

    public function __construct(
        protected AliExpressOrderFulfillmentService $fulfillmentService
    ) {}

    /**
     * Fulfill an order containing AliExpress products via the dropshipping API.
     */
    public function fulfill(FulfillAliExpressOrderRequest $request, Order $order): JsonResponse
    {
        try {
            $user = $this->user();

            // Get active AliExpress account for the seller (with fallback to latest connected)
            $account = AliExpressAccount::byUser($user->id)->active()->latest('connected_at')->first()
                ?? AliExpressAccount::byUser($user->id)->latest('id')->first();

            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 422);
            }

            // Verify the authenticated user is the seller of this order
            // Prevents User B from fulfilling User A's order using User A's AliExpress token
            $profile = $user->profile;
            if (! $profile || (int) $order->seller_id !== (int) $profile->id) {
                return ApiResponse::error('You are not the seller of this order.', 403);
            }

            $logisticsOptions = $request->input('logistics_options', []);
            $skuOptions = $request->input('sku_options') ?? $request->input('sku_map') ?? $request->input('skus') ?? [];

            // Extract any raw shipping address overrides from the request
            $topLevelShipping = array_filter([
                'line1' => $request->input('line1') ?? $request->input('address') ?? $request->input('address1'),
                'line2' => $request->input('line2') ?? $request->input('address2'),
                'city' => $request->input('city'),
                'state' => $request->input('state') ?? $request->input('province') ?? $request->input('region') ?? $request->input('county'),
                'province' => $request->input('province') ?? $request->input('state'),
                'postal_code' => $request->input('postal_code') ?? $request->input('zip') ?? $request->input('postcode'),
                'country' => $request->input('country') ?? $request->input('country_code'),
                'country_code' => $request->input('country_code') ?? $request->input('country'),
                'phone' => $request->input('phone') ?? $request->input('mobile_no'),
                'name' => $request->input('name') ?? $request->input('contact_person'),
            ], fn ($val) => ! is_null($val) && $val !== '');

            $nestedShipping = is_array($request->input('shipping_address')) ? $request->input('shipping_address') : [];
            $shippingOverride = array_merge($topLevelShipping, $nestedShipping);

            $options = array_merge($logisticsOptions, [
                'logistics_options' => $logisticsOptions,
                'sku_options' => $skuOptions,
                'shipping_address' => $shippingOverride,
            ]);

            $result = $this->fulfillmentService->execute($order, $account, $options);

            $message = ! empty($result['already_submitted'])
                ? 'Order was already submitted to AliExpress. Please complete payment.'
                : 'Order successfully pushed to AliExpress. Please complete payment.';

            $orderNumbers = $result['aliexpress_order_numbers'] ?? [];
            $firstOrderId = ! empty($orderNumbers) ? (string) reset($orderNumbers) : null;

            return ApiResponse::success([
                'payment_url' => $result['payment_url'],
                'payment_urls' => $result['payment_urls'] ?? null,
                'aliexpress_order_id' => $firstOrderId,
                'in_app_order_id' => $order->id,
                'aliexpress_order_numbers' => $orderNumbers,
                'status' => $order->fresh()->status,
            ], $message);

        } catch (Throwable $e) {
            Log::error('AliExpress order fulfillment failed: '.$e->getMessage(), [
                'order_id' => $order->id,
                'exception' => $e,
            ]);

            // Graceful recovery: if the order already has an active purchase record, return the existing payment link
            if ($order->hasPurchaseRecord()) {
                $orderNumbers = (array) ($order->aliexpress_order_numbers ?? []);
                $firstOrderId = (string) reset($orderNumbers);
                if ($firstOrderId !== '') {
                    return ApiResponse::success([
                        'payment_url' => app(AliExpressOrderClient::class)->buildPaymentUrl($firstOrderId, $orderNumbers),
                        // 'payment_urls' => app(AliExpressOrderClient::class)->buildPaymentUrls($firstOrderId, $orderNumbers),
                        'aliexpress_order_id' => $firstOrderId,
                        'in_app_order_id' => $order->id,
                        'aliexpress_order_numbers' => $orderNumbers,
                        'status' => $order->fresh()->status,
                    ], 'Order was already submitted to AliExpress. Please complete payment.');
                }
            }

            $statusCode = (int) $e->getCode();
            $httpStatus = ($statusCode >= 400 && $statusCode < 600) ? $statusCode : 200;

            // Provide actionable hints for known AliExpress fulfillment errors
            $message = $e->getMessage();
            $errors = null;

            if (str_contains($message, 'DELIVERY_METHOD_NOT_EXIST')) {
                $errors = [
                    'hints' => [
                        'Update the order shipping address to a country this supplier ships to.',
                        'Replace the product with one that supports shipping to the destination country.',
                        'Contact the AliExpress supplier to confirm available shipping destinations.',
                    ],
                ];
                $httpStatus = 200;
            } elseif (str_contains($message, 'PRODUCT_NOT_EXIST')) {
                $errors = [
                    'hints' => [
                        'This product no longer exists on AliExpress or the product ID is invalid.',
                        'Please cancel this order or ask the buyer to choose an alternative product.',
                    ],
                ];
                $httpStatus = 200;
            } elseif (str_contains($message, 'ERROR_WHEN_BUILD_FOR_PLACE_ORDER')) {
                $errors = [
                    'hints' => [
                        'AliExpress rejected the order during address/recipient validation.',
                        'Verify recipient city, state/province, postal code, and phone number formatting.',
                        'Ensure the supplier supports delivering this product/variant to the destination address.',
                    ],
                ];
                $httpStatus = 200;
            }

            return ApiResponse::error($message, $httpStatus, $errors);
        }
    }
}
