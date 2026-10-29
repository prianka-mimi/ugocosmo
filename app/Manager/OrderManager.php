<?php

namespace App\Manager;

use App\Models\EscrowPayment;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\OrderTimelineService;
use App\Services\StripeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderManager
{
    /**
     * Allowed status transitions (additive — includes the AliExpress
     * sourcing states awaiting_purchase → ready_to_ship as well as
     * the buyer refund and return lifecycle).
     */
    public const ALLOWED_TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'cancelled', 'awaiting_purchase', 'refund_requested', 'refunded'],
        'awaiting_purchase' => ['purchased', 'cancelled'],
        'purchased' => ['stock_received', 'cancelled'],
        'stock_received' => ['ready_to_ship', 'cancelled'],
        'ready_to_ship' => ['shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled', 'refund_requested', 'refunded'],
        'shipped' => ['delivered', 'cancelled', 'refund_requested', 'refunded'],
        'delivered' => ['completed', 'refund_requested', 'refunded'],
        'completed' => ['refund_requested', 'refunded'],
        'refund_requested' => ['refund_approved', 'refund_rejected', 'cancelled', 'refunded'],
        'refund_approved' => ['return_shipped', 'refunded', 'refund_rejected'],
        'return_shipped' => ['refunded', 'delivered'],
        'refund_rejected' => ['delivered', 'completed'],
        'cancelled' => [],
        'refunded' => [],
    ];

    protected NotificationManager $notificationManager;

    protected OrderTimelineService $timeline;

    public function __construct(
        NotificationManager $notificationManager,
        OrderTimelineService $timeline,
        protected InventoryService $inventory,
        protected EscrowManager $escrowManager,
        protected StripeService $stripe,
    ) {
        $this->notificationManager = $notificationManager;
        $this->timeline = $timeline;
    }

    public function myPurchases(array $filters = [], int $perPage = 20)
    {
        $user = Auth::guard('jwt')->user() ?? Auth::user();
        $profileId = (int) ($user?->profile?->id ?? 0);
        $userId = (int) ($user?->id ?? 0);

        return Order::forBuyer($profileId, $userId)
            ->filter($filters)
            ->latestFirst()
            ->with(['items.product', 'items.service', 'items.event', 'seller', 'transaction'])
            ->paginate($perPage);
    }

    public function mySales(array $filters = [], int $perPage = 20)
    {
        $user = Auth::guard('jwt')->user() ?? Auth::user();
        $profileId = (int) ($user?->profile?->id ?? 0);
        $userId = (int) ($user?->id ?? 0);

        return Order::forSeller($profileId, $userId)
            ->filter($filters)
            ->latestFirst()
            ->with(['items.product', 'items.service', 'items.event', 'buyer', 'transaction'])
            ->paginate($perPage);
    }

    public function show(Order $order): Order
    {
        if (! $this->isAuthorizedForOrder($order)) {
            abort(404);
        }

        return $order->load(['items.product', 'items.service', 'items.event', 'buyer.user', 'seller.user', 'transaction', 'timelineEvents.actor:id,full_name,username']);
    }

    /**
     * The order's timeline, visible to buyer and seller.
     */
    public function timeline(Order $order): Order
    {
        if (! $this->isAuthorizedForOrder($order)) {
            abort(404);
        }

        return $order->load(['timelineEvents.actor:id,full_name,username']);
    }

    /**
     * Verify whether authenticated user is either the buyer or the seller
     * of the order, or owns any of the products/services/events in this order.
     */
    public function isAuthorizedForOrder(Order $order): bool
    {
        $user = Auth::guard('jwt')->user() ?? Auth::user();
        $profileId = (int) ($user?->profile?->id ?? 0);
        $userId = (int) ($user?->id ?? 0);

        if ($profileId > 0 && ((int) $order->buyer_id === $profileId || (int) $order->seller_id === $profileId)) {
            return true;
        }

        if ($userId > 0 && ((int) $order->buyer_id === $userId || (int) $order->seller_id === $userId)) {
            return true;
        }

        // Check if user owns any items in this order
        return $order->items()->where(function ($q) use ($profileId, $userId) {
            $q->whereHas('product', function ($pq) use ($profileId, $userId) {
                $pq->where(function ($sub) use ($profileId, $userId) {
                    if ($profileId > 0) {
                        $sub->where('seller_id', $profileId);
                    }
                    if ($userId > 0) {
                        $sub->orWhere('seller_id', $userId);
                    }
                });
            })
                ->orWhereHas('service', function ($sq) use ($profileId, $userId) {
                    $sq->where(function ($sub) use ($profileId, $userId) {
                        if ($profileId > 0) {
                            $sub->where('provider_id', $profileId);
                        }
                        if ($userId > 0) {
                            $sub->orWhere('provider_id', $userId);
                        }
                    });
                })
                ->orWhereHas('event', function ($eq) use ($profileId, $userId) {
                    $eq->where(function ($sub) use ($profileId, $userId) {
                        if ($profileId > 0) {
                            $sub->where('organizer_id', $profileId);
                        }
                        if ($userId > 0) {
                            $sub->orWhere('organizer_id', $userId);
                        }
                    });
                });
        })->exists();
    }

    /**
     * Seller-driven status transition (existing contract, unchanged).
     *
     * $notes / $metadata are recorded on the timeline event created here —
     * callers should pass notes instead of recording a second, duplicate
     * timeline event for the same transition.
     */
    public function updateStatus(Order $order, string $status, ?string $notes = null, ?array $metadata = null): Order
    {
        $user = Auth::guard('jwt')->user() ?? Auth::user();
        $profileId = (int) ($user?->profile?->id ?? 0);
        $userId = (int) ($user?->id ?? 0);

        $isSeller = ($profileId > 0 && (int) $order->seller_id === $profileId)
            || ($userId > 0 && (int) $order->seller_id === $userId)
            || $this->isAuthorizedForOrder($order);

        // Only seller can update order status
        if (! $isSeller) {
            abort(403, 'Only the seller can update order status');
        }

        $effectiveActorProfileId = $profileId > 0 ? $profileId : (int) $order->seller_id;
        $this->applyTransition($order, $status, $effectiveActorProfileId, OrderTimelineService::ACTOR_SELLER, $notes, $metadata);

        return $order->fresh()->load(['items', 'buyer.user', 'seller.user', 'transaction', 'timelineEvents.actor:id,full_name,username']);
    }

    /**
     * Validate and apply a single transition, with side effects and a
     * timeline event.
     */
    protected function applyTransition(Order $order, string $status, int $actorProfileId, string $actorRole, ?string $notes = null, ?array $metadata = null): void
    {
        $currentStatus = $order->status;
        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($status, $allowed)) {
            throw new \Exception(
                "Cannot transition from '{$currentStatus}' to '{$status}'. ".
                'Allowed transitions: '.implode(', ', $allowed),
                422
            );
        }

        DB::transaction(function () use ($order, $status, $currentStatus, $actorProfileId, $actorRole, $notes, $metadata) {
            $order->update(['status' => $status]);

            // Update transaction status when order completes, cancels, or refunds
            if (in_array($status, ['completed', 'delivered'])) {
                $order->transaction()?->update(['status' => 'completed']);
            } elseif (in_array($status, ['cancelled', 'refunded'])) {
                $order->transaction()?->update(['status' => $status]);

                // Release stock reservations — but only before shipment. A
                // shipped order's goods have physically left the seller, its
                // stock was already deducted on ship(), so there is nothing
                // reserved to release (and releasing would corrupt accounting).
                if (! in_array($currentStatus, ['shipped', 'delivered', 'completed'], true)) {
                    $this->releaseReservedStock($order, $actorProfileId);
                }
            }

            $this->notificationManager->dispatch(
                (int) $order->buyer_id,
                'store.order_updated',
                'Order Status Updated',
                "Your order #{$order->id} status has been updated to {$status}.",
                ['order_id' => $order->id, 'status' => $status]
            );

            $this->timeline->record(
                $order,
                $status,
                $actorProfileId,
                $actorRole,
                $notes,
                array_merge(['from' => $currentStatus], $metadata ?? [])
            );
        });
    }

    /**
     * Release this order's stock reservations (order cancelled pre-ship).
     *
     * Generic orders confirmed via the plain status endpoint never reserved
     * anything — those products have reserved_quantity 0 and are skipped.
     * AliExpress-fulfilled orders reserve on confirm; the full reservation
     * is released here. Each release is clamped to the currently reserved
     * quantity so an already-partially-released product can never go
     * negative (InventoryService additionally guards this atomically).
     */
    protected function releaseReservedStock(Order $order, int $actorProfileId): void
    {
        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            $product = $item->product;

            if (! $product || (int) $product->reserved_quantity <= 0) {
                continue;
            }

            $quantity = min((int) $item->quantity, (int) $product->reserved_quantity);
            if ($quantity <= 0) {
                continue;
            }

            $this->inventory->release(
                $product,
                $quantity,
                'order',
                $order->id,
                "Reservation released: order #{$order->id} cancelled",
                $actorProfileId
            );
        }
    }

    /**
     * Buyer initiates a refund request for the order (or a specific item).
     */
    public function requestRefund(Order $order, array $data, int $buyerProfileId): Order
    {
        if ((int) $order->buyer_id !== $buyerProfileId) {
            throw new \Exception('Only the buyer of this order can request a refund.', 403);
        }

        if (in_array($order->status, ['cancelled', 'refunded', 'refund_requested'], true)) {
            throw new \Exception("Cannot request refund for order with status '{$order->status}'.", 422);
        }

        $reason = $data['reason'];
        $notes = $data['notes'] ?? ($data['description'] ?? null);
        $productId = $data['product_id'] ?? null;
        $orderItemId = $data['order_item_id'] ?? null;
        $images = $data['images'] ?? ($data['evidence_urls'] ?? null);

        $item = null;
        if ($orderItemId) {
            $item = $order->items()->where('id', $orderItemId)->first();
        } elseif ($productId) {
            $item = $order->items()->where('product_id', $productId)->first();
        }

        $metadata = [
            'reason' => $reason,
            'notes' => $notes,
            'product_id' => $item?->product_id ?? $productId,
            'order_item_id' => $item?->id ?? $orderItemId,
            'product_title' => $item?->title,
            'refund_amount' => $item ? (float) $item->total_price : (float) $order->total,
            'evidence_urls' => $images,
            'requested_at' => now()->toIso8601String(),
        ];

        return DB::transaction(function () use ($order, $buyerProfileId, $reason, $notes, $metadata) {
            $order->update(['status' => 'refund_requested']);

            $this->timeline->record(
                $order,
                'refund_requested',
                $buyerProfileId,
                OrderTimelineService::ACTOR_BUYER,
                "Refund requested: {$reason}".($notes ? " ({$notes})" : ''),
                $metadata
            );

            $this->notificationManager->dispatch(
                (int) $order->seller_id,
                'store.refund_requested',
                'Refund Requested',
                "Buyer requested a refund for Order #{$order->id}: {$reason}",
                ['order_id' => $order->id, 'reason' => $reason, 'metadata' => $metadata]
            );

            return $order->fresh()->load(['items.product', 'buyer.user', 'seller.user', 'transaction', 'timelineEvents.actor:id,full_name,username']);
        });
    }

    /**
     * Seller reviews and approves buyer's refund request.
     */
    public function approveRefund(Order $order, array $data, int $sellerProfileId): Order
    {
        if ((int) $order->seller_id !== $sellerProfileId) {
            throw new \Exception('Only the seller can approve this refund request.', 403);
        }

        if ($order->status !== 'refund_requested') {
            throw new \Exception("Cannot approve refund for order with status '{$order->status}'. It must be 'refund_requested'.", 422);
        }

        $notes = $data['notes'] ?? ($data['return_instructions'] ?? 'Refund request approved. Please return the product to the seller.');

        return DB::transaction(function () use ($order, $sellerProfileId, $notes, $data) {
            $order->update(['status' => 'refund_approved']);

            $this->timeline->record(
                $order,
                'refund_approved',
                $sellerProfileId,
                OrderTimelineService::ACTOR_SELLER,
                $notes,
                array_merge(['approved_at' => now()->toIso8601String()], $data)
            );

            $this->notificationManager->dispatch(
                (int) $order->buyer_id,
                'store.refund_approved',
                'Refund Request Approved',
                "Your refund request for Order #{$order->id} was approved by the seller. Please proceed to return the item.",
                ['order_id' => $order->id, 'notes' => $notes]
            );

            return $order->fresh()->load(['items.product', 'buyer.user', 'seller.user', 'transaction', 'timelineEvents.actor:id,full_name,username']);
        });
    }

    /**
     * Seller reviews and rejects buyer's refund request.
     */
    public function rejectRefund(Order $order, array $data, int $sellerProfileId): Order
    {
        if ((int) $order->seller_id !== $sellerProfileId) {
            throw new \Exception('Only the seller can reject this refund request.', 403);
        }

        if ($order->status !== 'refund_requested') {
            throw new \Exception("Cannot reject refund for order with status '{$order->status}'. It must be 'refund_requested'.", 422);
        }

        $reason = $data['reason'] ?? 'Refund request was rejected by seller.';

        return DB::transaction(function () use ($order, $sellerProfileId, $reason, $data) {
            $order->update(['status' => 'refund_rejected']);

            $this->timeline->record(
                $order,
                'refund_rejected',
                $sellerProfileId,
                OrderTimelineService::ACTOR_SELLER,
                "Refund rejected: {$reason}",
                array_merge(['rejected_at' => now()->toIso8601String(), 'reason' => $reason], $data)
            );

            $this->notificationManager->dispatch(
                (int) $order->buyer_id,
                'store.refund_rejected',
                'Refund Request Rejected',
                "Your refund request for Order #{$order->id} was rejected: {$reason}",
                ['order_id' => $order->id, 'reason' => $reason]
            );

            return $order->fresh()->load(['items.product', 'buyer.user', 'seller.user', 'transaction', 'timelineEvents.actor:id,full_name,username']);
        });
    }

    /**
     * Buyer returns/ships the approved product back to the seller.
     */
    public function shipReturn(Order $order, array $data, int $buyerProfileId): Order
    {
        if ((int) $order->buyer_id !== $buyerProfileId) {
            throw new \Exception('Only the buyer can record the return shipment.', 403);
        }

        if (! in_array($order->status, ['refund_approved', 'refund_requested'], true)) {
            throw new \Exception("Cannot return item for order with status '{$order->status}'. Refund must be approved first.", 422);
        }

        $trackingNumber = $data['tracking_number'] ?? ($data['return_tracking_number'] ?? null);
        $courier = $data['courier_name'] ?? ($data['carrier'] ?? null);
        $notes = $data['notes'] ?? 'Product returned/shipped back to seller.';

        return DB::transaction(function () use ($order, $buyerProfileId, $trackingNumber, $courier, $notes) {
            $order->update(['status' => 'return_shipped']);

            $metadata = [
                'return_tracking_number' => $trackingNumber,
                'return_courier' => $courier,
                'shipped_at' => now()->toIso8601String(),
            ];

            $this->timeline->record(
                $order,
                'return_shipped',
                $buyerProfileId,
                OrderTimelineService::ACTOR_BUYER,
                $trackingNumber ? "Product shipped back to seller. Tracking: {$trackingNumber}".($courier ? " ({$courier})" : '') : $notes,
                $metadata
            );

            $this->notificationManager->dispatch(
                (int) $order->seller_id,
                'store.return_shipped',
                'Returned Product Shipped',
                "Buyer has shipped back the item for Order #{$order->id}.".($trackingNumber ? " Tracking: {$trackingNumber}" : ''),
                ['order_id' => $order->id, 'tracking_number' => $trackingNumber, 'courier' => $courier]
            );

            return $order->fresh()->load(['items.product', 'buyer.user', 'seller.user', 'transaction', 'timelineEvents.actor:id,full_name,username']);
        });
    }

    /**
     * Process refund and return execution:
     * Disburses the money back to the buyer's account via Stripe, cancels escrow,
     * updates order and transaction statuses to 'refunded', and notifies both parties.
     */
    public function processRefund(Order $order, array $data, int $actorProfileId, bool $isAdmin = false): array
    {
        if (! $isAdmin && (int) $order->seller_id !== $actorProfileId) {
            throw new \Exception('Only the seller or an administrator can issue this refund.', 403);
        }

        if ($order->status === 'refunded') {
            throw new \Exception('Order is already refunded.', 422);
        }

        $amount = isset($data['amount']) ? (float) $data['amount'] : (float) $order->total;
        $amountCents = (int) round($amount * 100);
        $reason = $data['reason'] ?? 'requested_by_customer';

        $paymentIntentId = $order->stripe_payment_intent_id;
        $refundResult = null;
        if ($paymentIntentId && str_starts_with((string) $paymentIntentId, 'pi_')) {
            $refundResult = $this->stripe->refund(
                $paymentIntentId,
                $amountCents,
                $reason
            );
        } else {
            $refundResult = [
                'id' => 're_manual_'.substr(md5((string) $order->id.time()), 0, 16),
                'amount' => $amountCents,
                'currency' => strtolower($order->shipping_currency ?? 'usd'),
                'status' => 'succeeded',
            ];
        }

        return DB::transaction(function () use ($order, $actorProfileId, $amount, $refundResult) {
            $order->update(['status' => 'refunded']);
            $order->transaction?->update(['status' => 'refunded']);

            // Cancel / refund active escrow payment so funds are returned to buyer and seller is not paid out
            EscrowPayment::where('order_id', $order->id)
                ->whereIn('status', ['held', 'pending', 'disputed'])
                ->update([
                    'status' => 'cancelled',
                    'payout_reference' => 'REFUNDED',
                ]);

            // Restock local physical inventory if available
            $order->loadMissing('items.product');
            foreach ($order->items as $item) {
                $product = $item->product;
                if ($product && strtolower((string) $product->source) !== 'aliexpress') {
                    try {
                        $this->inventory->adjust($product, (int) $item->quantity, "Restocked from returned order #{$order->id}", $actorProfileId);
                    } catch (\Throwable $e) {
                        Log::warning("Could not restock product #{$product->id}: {$e->getMessage()}");
                    }
                }
            }

            $this->timeline->record(
                $order,
                'refunded',
                $actorProfileId,
                OrderTimelineService::ACTOR_SELLER,
                "Return confirmed and refund disbursed to buyer (\${$amount}).",
                [
                    'refund_id' => $refundResult['id'] ?? null,
                    'refund_amount' => $amount,
                    'currency' => $order->shipping_currency ?? 'USD',
                    'refunded_at' => now()->toIso8601String(),
                ]
            );

            $this->notificationManager->dispatch(
                (int) $order->buyer_id,
                'store.refund_completed',
                'Refund Disbursed',
                "Refund of \${$amount} for Order #{$order->id} has been automatically returned to your original payment method.",
                ['order_id' => $order->id, 'amount' => $amount]
            );

            $this->notificationManager->dispatch(
                (int) $order->seller_id,
                'store.refund_completed',
                'Refund Completed',
                "Refund of \${$amount} for Order #{$order->id} has been completed successfully.",
                ['order_id' => $order->id, 'amount' => $amount]
            );

            return [
                'refund_id' => $refundResult['id'] ?? null,
                'amount' => $amount,
                'currency' => strtoupper($refundResult['currency'] ?? ($order->shipping_currency ?? 'USD')),
                'status' => $refundResult['status'] ?? 'succeeded',
                'order_id' => $order->id,
                'order' => $order->fresh()->load(['items.product', 'buyer.user', 'seller.user', 'transaction', 'timelineEvents.actor:id,full_name,username']),
            ];
        });
    }

    /**
     * Cancel an order transactionally and refund captured payment.
     */
    public function cancelOrder(Order $order, int $actorProfileId, bool $isBuyer): Order
    {
        if (in_array($order->status, ['cancelled', 'completed'], true)) {
            throw new \Exception("Order is already {$order->status}.", 422);
        }

        return DB::transaction(function () use ($order, $actorProfileId, $isBuyer) {
            $order->update(['status' => 'cancelled']);
            $order->transaction?->update(['status' => 'cancelled']);

            // Cancel any active escrow payment
            EscrowPayment::where('order_id', $order->id)
                ->whereIn('status', ['held', 'pending', 'disputed'])
                ->update(['status' => 'cancelled', 'payout_reference' => 'CANCELLED']);

            // Release stock reservations if pre-ship
            if (! in_array($order->getOriginal('status'), ['shipped', 'delivered', 'completed'], true)) {
                $this->releaseReservedStock($order, $actorProfileId);
            }

            // Stripe auto-refund if payment intent is present
            $paymentIntentId = $order->stripe_payment_intent_id;
            if ($paymentIntentId && str_starts_with((string) $paymentIntentId, 'pi_')) {
                try {
                    $this->stripe->refund($paymentIntentId, null, 'requested_by_customer');
                } catch (\Throwable $refundEx) {
                    Log::warning('Stripe auto-refund failed on order cancel: '.$refundEx->getMessage(), [
                        'order_id' => $order->id,
                        'payment_intent_id' => $paymentIntentId,
                    ]);
                }
            }

            $this->timeline->record(
                $order,
                'cancelled',
                $actorProfileId,
                $isBuyer ? OrderTimelineService::ACTOR_BUYER : OrderTimelineService::ACTOR_SELLER,
                'Order cancelled by '.($isBuyer ? 'buyer' : 'seller').'.'
            );

            $otherProfileId = $isBuyer ? (int) $order->seller_id : (int) $order->buyer_id;
            $this->notificationManager->dispatch(
                $otherProfileId,
                'store.order_cancelled',
                'Order Cancelled',
                "Order #{$order->id} was cancelled by the ".($isBuyer ? 'buyer' : 'seller').'.',
                ['order_id' => $order->id]
            );

            return $order->fresh()->load(['items.product', 'buyer.user', 'seller.user', 'transaction', 'timelineEvents.actor:id,full_name,username']);
        });
    }

    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }
}
