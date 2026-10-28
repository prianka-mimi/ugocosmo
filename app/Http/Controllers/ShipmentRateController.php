<?php

namespace App\Http\Controllers;

use App\Http\Requests\SelectShippingRateRequest;
use App\Models\Order;
use App\Models\Profile;
use App\Models\User;
use App\Services\ShipmentService;
use App\Services\StripePaymentIntentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shipping-rate / carrier selection for buyers and sellers.
 *
 * Flow:
 * - Buyer checkout: selects rate before payment → updates PaymentIntent.
 * - Seller fulfillment: selects carrier / rate before creating shipment label.
 */
class ShipmentRateController extends Controller
{
    public function __construct(
        protected ShipmentService $shipments,
        protected StripePaymentIntentService $payments,
    ) {}

    /**
     * Select Verified Shipping Rate or Carrier
     * POST /api/shipping/rates/select
     */
    public function select(SelectShippingRateRequest $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            $userId = (int) $user->id;
            $order = Order::findOrFail((int) $request->input('order_id'));
            $validated = $request->validated();

            $rateId = $validated['rate_id'] ?? $validated['carrier_id'] ?? null;
            $carrierId = $validated['carrier_id'] ?? null;
            $serviceCode = $validated['service_code'] ?? null;

            $result = $this->shipments->selectRate(
                $order,
                $userId,
                $rateId !== null ? (string) $rateId : null,
                $validated['rate_options'] ?? [],
                $carrierId,
                $serviceCode,
            );

            // Update/replace the payment intent if the order is still awaiting initial payment
            if (! in_array($order->status, ['processing', 'ready_to_ship', 'shipped', 'delivered', 'completed'], true)) {
                try {
                    $this->payments->updateIntentAmountForOrder($result['order']);
                } catch (Throwable $e) {
                    Log::warning('Payment intent update skipped during rate selection: '.$e->getMessage());
                }
            }

            return ApiResponse::success([
                'order' => [
                    'id' => $result['order']->id,
                    'status' => $result['order']->status,
                    'subtotal' => (float) $result['order']->subtotal,
                    'shipping_amount' => (float) $result['order']->shipping_amount,
                    'buyer_fee' => (float) $result['order']->buyer_fee,
                    'platform_fee_total' => (float) $result['order']->platform_fee_total,
                    'tax_amount' => (float) $result['order']->tax_amount,
                    'total' => (float) $result['order']->total,
                    'currency' => $result['order']->shipping_currency,
                ],
                'selected_rate' => $result['rate'],
                'amount_changed' => $result['amount_changed'],
            ], 'Shipping rate selected.');
        } catch (Throwable $e) {
            Log::error('Shipping rate selection failed: '.$e->getMessage());

            return $this->errorResponse($e);
        }
    }

    /**
     * Map errors to safe responses (mirrors ShipmentController).
     */
    protected function errorResponse(Throwable $e): JsonResponse
    {
        return ApiResponse::error($e->getMessage(), $e->getCode() > 0 && $e->getCode() <= 599 ? $e->getCode() : 500);
    }

    protected function resolveUser(?Request $request = null): User
    {
        $user = Auth::guard('jwt')->user()
            ?? auth()->user()
            ?? Auth::guard('api')->user()
            ?? Auth::guard('web')->user();

        if (! $user && $request) {
            $token = $request->bearerToken();

            if (! $token) {
                $rawToken = $request->cookie('token')
                    ?? $request->cookies->get('token')
                    ?? ($_COOKIE['token'] ?? null);

                if (! $rawToken && $request->headers->has('cookie')) {
                    $cookieHeader = (string) $request->header('cookie');
                    if (preg_match('/(?:^|;\s*)token=([^;]+)/', $cookieHeader, $matches)) {
                        $rawToken = urldecode(trim($matches[1], "\"'\t\n\r\0\x0B "));
                    }
                }

                if ($rawToken) {
                    $rawToken = trim((string) $rawToken, "\"'\t\n\r\0\x0B ");
                    $token = str_starts_with($rawToken, 'Bearer ') ? substr($rawToken, 7) : $rawToken;
                }
            }

            if ($token) {
                try {
                    $user = Auth::guard('jwt')->setToken($token)->user();
                } catch (Throwable) {
                    // Token invalid or expired
                }
            }
        }

        if (! $user) {
            throw new \Exception('Authentication required.', 401);
        }

        return $user;
    }

    protected function profile(): Profile
    {
        $user = $this->resolveUser();
        $profile = $user->profile;
        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return $profile;
    }
}
