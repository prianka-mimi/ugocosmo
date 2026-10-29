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

            $updatedOrder = $this->orderManager->cancelOrder($order, $profileId, $isBuyer);

            $this->data = OrderResource::make($updatedOrder);
            $this->status_message = 'Order cancelled successfully.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Order cancel error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $code = (int) $e->getCode();
            $this->status_code = ($code >= 400 && $code < 600) ? $code : 500;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * POST /api/orders/{order}/request-refund
     *
     * Buyer initiates a refund request for the order or a specific item.
     */
    public function requestRefund(Request $request, Order $order): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:500'],
                'description' => ['nullable', 'string', 'max:2000'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'product_id' => ['nullable', 'integer', 'exists:products,id'],
                'order_item_id' => ['nullable', 'integer', 'exists:order_items,id'],
                'images' => ['nullable', 'array'],
                'images.*' => ['string'],
            ]);

            $updatedOrder = $this->orderManager->requestRefund($order, $validated, (int) $profile->id);

            $this->data = OrderResource::make($updatedOrder);
            $this->status_message = 'Refund request submitted successfully to seller.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Refund request error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $code = (int) $e->getCode();
            $this->status_code = ($code >= 400 && $code < 600) ? $code : 400;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * POST /api/orders/{order}/approve-refund
     *
     * Seller reviews and approves buyer's refund/return request.
     */
    public function approveRefund(Request $request, Order $order): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            $validated = $request->validate([
                'notes' => ['nullable', 'string', 'max:1000'],
                'return_instructions' => ['nullable', 'string', 'max:1000'],
            ]);

            $updatedOrder = $this->orderManager->approveRefund($order, $validated, (int) $profile->id);

            $this->data = OrderResource::make($updatedOrder);
            $this->status_message = 'Refund request approved. Waiting for buyer to return product.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Approve refund error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $code = (int) $e->getCode();
            $this->status_code = ($code >= 400 && $code < 600) ? $code : 400;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * POST /api/orders/{order}/reject-refund
     *
     * Seller reviews and rejects buyer's refund request.
     */
    public function rejectRefund(Request $request, Order $order): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:500'],
            ]);

            $updatedOrder = $this->orderManager->rejectRefund($order, $validated, (int) $profile->id);

            $this->data = OrderResource::make($updatedOrder);
            $this->status_message = 'Refund request rejected.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Reject refund error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $code = (int) $e->getCode();
            $this->status_code = ($code >= 400 && $code < 600) ? $code : 400;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * POST /api/orders/{order}/ship-return
     *
     * Buyer returns/ships the approved product back to the seller.
     */
    public function shipReturn(Request $request, Order $order): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            $validated = $request->validate([
                'tracking_number' => ['nullable', 'string', 'max:255'],
                'return_tracking_number' => ['nullable', 'string', 'max:255'],
                'courier_name' => ['nullable', 'string', 'max:255'],
                'carrier' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);

            $updatedOrder = $this->orderManager->shipReturn($order, $validated, (int) $profile->id);

            $this->data = OrderResource::make($updatedOrder);
            $this->status_message = 'Return product shipment recorded. Waiting for seller to confirm receipt.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Ship return error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $code = (int) $e->getCode();
            $this->status_code = ($code >= 400 && $code < 600) ? $code : 400;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * POST /api/orders/{order}/confirm-return
     *
     * Seller confirms receipt of the returned product.
     * Automatically disburses the refund money back to the buyer via Stripe and cancels escrow.
     */
    public function confirmReturn(Request $request, Order $order): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            $validated = $request->validate([
                'amount' => ['nullable', 'numeric', 'min:0.01'],
                'reason' => ['nullable', 'string', 'in:duplicate,fraudulent,requested_by_customer'],
            ]);

            $result = $this->orderManager->processRefund($order, $validated, (int) $profile->id);

            $this->data = [
                'refund_id' => $result['refund_id'],
                'amount' => $result['amount'],
                'currency' => $result['currency'],
                'status' => $result['status'],
                'order_id' => $result['order_id'],
                'order' => OrderResource::make($result['order']),
            ];
            $this->status_message = 'Return confirmed and refund disbursed successfully.';
            $this->status_code = 200;
        } catch (Throwable $e) {
            Log::error('Confirm return error: '.$e->getMessage());
            $this->status_message = $e->getMessage();
            $code = (int) $e->getCode();
            $this->status_code = ($code >= 400 && $code < 600) ? $code : 400;
        }

        return $this->JsonResponseFormat();
    }

    /**
     * POST /api/orders/{order}/refund
     *
     * Seller-initiated manual refund (full or partial) via Stripe.
     * Automatically completes the refund, updates statuses, cancels escrow, and notifies buyer.
     */
    public function refund(Request $request, Order $order): JsonResponse
    {
        try {
            $profile = Auth::guard('jwt')->user()->profile;
            $validated = $request->validate([
                'amount' => ['nullable', 'numeric', 'min:0.01'],
                'reason' => ['nullable', 'string', 'in:duplicate,fraudulent,requested_by_customer'],
            ]);

            $result = $this->orderManager->processRefund($order, $validated, (int) $profile->id);

            $this->data = [
                'refund_id' => $result['refund_id'],
                'amount' => $result['amount'],
                'currency' => $result['currency'],
                'status' => $result['status'],
                'order_id' => $result['order_id'],
                'order' => OrderResource::make($result['order']),
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
