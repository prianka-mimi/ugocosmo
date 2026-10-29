<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\OrderIndexRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderTimelineEventResource;
use App\Manager\CartManager;
use App\Manager\CheckoutManager;
use App\Manager\OrderManager;
use App\Models\Order;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderController extends Controller
{
    protected CheckoutManager $checkoutManager;

    protected OrderManager $orderManager;

    protected CartManager $cartManager;

    public function __construct(CheckoutManager $checkoutManager, OrderManager $orderManager, CartManager $cartManager)
    {
        $this->checkoutManager = $checkoutManager;
        $this->orderManager = $orderManager;
        $this->cartManager = $cartManager;
    }

    public function index(OrderIndexRequest $request): JsonResponse
    {
        $type = $request->input('type') ?? ($request->input('role') === 'seller' ? 'sales' : ($request->input('role') === 'buyer' ? 'purchases' : null));

        if ($type === 'purchases') {
            return $this->purchases($request);
        }

        return $this->sales($request);
    }

    public function purchases(OrderIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $filters = $request->validated();
            $this->data = OrderResource::collection($this->orderManager->myPurchases($filters, $perPage))->response()->getData();
            $this->status_message = 'Purchases retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Purchases retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function sales(OrderIndexRequest $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $filters = $request->validated();
            $this->data = OrderResource::collection($this->orderManager->mySales($filters, $perPage))->response()->getData();
            $this->status_message = 'Sales retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Sales retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function show(Order $order): JsonResponse
    {
        try {
            $this->data = OrderResource::make($this->orderManager->show($order));
            $this->status_message = 'Order retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Order retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function confirmation(Order $order): JsonResponse
    {
        try {
            $user = \Illuminate\Support\Facades\Auth::guard('jwt')->user();
            $profile = $user ? $user->profile : null;
            $profileId = $profile ? (int) $profile->id : null;

            if (!$profileId || ((int) $order->buyer_id !== $profileId && (int) $order->seller_id !== $profileId)) {
                abort(403, 'Unauthorized to view this order confirmation.');
            }

            $order->load(['items.product', 'items.service', 'items.event', 'transaction']);

            $this->data = new \App\Http\Resources\OrderConfirmationResource($order);
            $this->status_message = 'Order confirmation details retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Order confirmation retrieve error: ' . $e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function timeline(Order $order): JsonResponse
    {
        try {
            $order = $this->orderManager->timeline($order);
            $this->data = OrderTimelineEventResource::collection($order->timelineEvents);
            $this->status_message = 'Order timeline retrieved successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Order timeline retrieve error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * GET /api/orders/checkout
     *
     * Retrieve all checkout preview data: cart items, product options, available
     * shipping methods, saved addresses, active shipping address, live price calculation,
     * and the exact payload template needed for POST /api/orders/checkout.
     */
    public function checkoutSummary(Request $request): JsonResponse
    {
        try {
            $this->data = $this->checkoutManager->preview($request->all());
            $this->status_message = 'Checkout summary retrieved successfully.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Checkout summary error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        try {
            $result = $this->checkoutManager->checkout($request->validated());

            $this->data = OrderResource::collection($result['orders']);
            $this->status_message = 'Order placed successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Checkout error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        try {
            $request->validate(['status' => ['required', 'string']]);
            $this->data = OrderResource::make($this->orderManager->updateStatus($order, $request->input('status')));
            $this->status_message = 'Order status updated';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Order status update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }


    /**
     * PATCH /api/orders/{order}/shipping-address
     *
     * Update the shipping address for an order. Seller only.
     * Used to fix incomplete addresses before shipping.
     */
    public function updateShippingAddress(Request $request, Order $order): JsonResponse
    {
        try {
            $addressFields = ['name', 'line1', 'line2', 'city', 'state', 'postal_code', 'country', 'phone', 'email'];

            if ($request->hasAny($addressFields)) {
                $shippingAddress = [];
                foreach ($addressFields as $field) {
                    if ($request->has($field)) {
                        $shippingAddress[$field] = $request->input($field);
                    }
                }
                $request->merge(['shipping_address' => $shippingAddress]);
            }

            $validated = $request->validate([
                'shipping_address' => ['required', 'array'],
                'shipping_address.name' => ['required', 'string', 'max:255'],
                'shipping_address.line1' => ['required', 'string', 'max:500'],
                'shipping_address.line2' => ['nullable', 'string', 'max:500'],
                'shipping_address.city' => ['required', 'string', 'max:255'],
                'shipping_address.state' => ['nullable', 'string', 'max:255'],
                'shipping_address.postal_code' => ['required', 'string', 'max:20'],
                'shipping_address.country' => ['required', 'string', 'max:10'],
                'shipping_address.phone' => ['nullable', 'string', 'max:50'],
                'shipping_address.email' => ['nullable', 'email', 'max:255'],
            ]);

            $profile = Auth::guard('jwt')->user()->profile;
            if ((int) $order->seller_id !== (int) $profile->id && (int) $order->buyer_id !== (int) $profile->id) {
                throw new \Exception('Only the buyer or seller of this order can update the shipping address.', 403);
            }

            $order->update([
                'shipping_address' => $validated['shipping_address'],
            ]);

            $this->data = OrderResource::make($order->fresh()->load(['items', 'buyer', 'seller', 'transaction']));
            $this->status_message = 'Shipping address updated successfully';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Shipping address update error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * POST /api/orders/{order}/cancel
     *
     * Cancel an order. Buyer can cancel before shipment; seller can cancel anytime before delivery.
     * Automatically triggers a Stripe full refund if payment was captured.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            $profileId = (int) $profile->id;
            $isBuyer = (int) $order->buyer_id === $profileId;
            $isSeller = (int) $order->seller_id === $profileId;

            if (! $isBuyer && ! $isSeller) {
                throw new \Exception('Only the buyer or seller of this order can cancel it.', 403);
            }

            // Buyer cannot cancel after shipment
            if ($isBuyer && in_array($order->status, ['shipped', 'delivered', 'completed'], true)) {
                throw new \Exception('Order cannot be cancelled after it has been shipped.', 422);
            }

            if (in_array($order->status, ['cancelled', 'completed'], true)) {
                throw new \Exception("Order is already {$order->status}.", 422);
            }

            DB::transaction(function () use ($order, $profileId, $isBuyer) {
                $order->update(['status' => 'cancelled']);
                $order->transaction?->update(['status' => 'cancelled']);

                // Stripe refund if payment intent is present
                $paymentIntentId = $order->stripe_payment_intent_id;
                $refundResult = null;
                if ($paymentIntentId && str_starts_with((string) $paymentIntentId, 'pi_')) {
                    try {
                        $stripe = app(StripeService::class);
                        $refundResult = $stripe->refund($paymentIntentId, null, 'requested_by_customer');
                    } catch (Throwable $refundEx) {
                        Log::warning('Stripe auto-refund failed on order cancel: '.$refundEx->getMessage(), [
                            'order_id' => $order->id,
                            'payment_intent_id' => $paymentIntentId,
                        ]);
                        // Non-blocking: order is still cancelled; admin can refund manually
                    }
                }

                Log::info('Order #'.$order->id.' cancelled by profile #'.$profileId.
                    ($isBuyer ? ' (buyer)' : ' (seller)'));
            });

            $this->data = OrderResource::make($order->fresh()->load(['items', 'buyer', 'seller', 'transaction']));
            $this->status_message = 'Order cancelled successfully.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Order cancel error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $this->status_code = $e->getCode() ?: 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * POST /api/orders/{order}/refund
     *
     * Seller-initiated manual refund (full or partial) via Stripe.
     * Does NOT change the order status — seller updates status separately if needed.
     */
    public function refund(Request $request, Order $order): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            if ((int) $order->seller_id !== (int) $profile->id) {
                throw new \Exception('Only the seller can issue a refund.', 403);
            }

            $validated = $request->validate([
                'amount' => ['nullable', 'numeric', 'min:0.01'],
                'reason' => ['nullable', 'string', 'in:duplicate,fraudulent,requested_by_customer'],
            ]);

            $paymentIntentId = $order->stripe_payment_intent_id;
            if (! $paymentIntentId || ! str_starts_with((string) $paymentIntentId, 'pi_')) {
                throw new \Exception('No Stripe PaymentIntent found for this order. Refund must be processed manually.', 422);
            }

            $amountCents = isset($validated['amount'])
                ? (int) round((float) $validated['amount'] * 100)
                : null;

            $stripe = app(StripeService::class);
            $refund = $stripe->refund(
                $paymentIntentId,
                $amountCents,
                $validated['reason'] ?? 'requested_by_customer',
            );

            Log::info('Refund issued for Order #'.$order->id, [
                'refund_id' => $refund['id'] ?? null,
                'amount' => $refund['amount'] ?? null,
                'status' => $refund['status'] ?? null,
            ]);

            $this->data = [
                'refund_id' => $refund['id'] ?? null,
                'amount' => isset($refund['amount']) ? round($refund['amount'] / 100, 2) : (float) $order->total,
                'currency' => strtoupper($refund['currency'] ?? 'usd'),
                'status' => $refund['status'] ?? 'succeeded',
                'order_id' => $order->id,
            ];
            $this->status_message = 'Refund issued successfully.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Order refund error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $code = (int) $e->getCode();
            $this->status_code = ($code >= 400 && $code < 600) ? $code : 400;
        }

        return $this->JsonResponseFormat();
    }
}
