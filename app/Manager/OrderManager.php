<?php

namespace App\Manager;

use App\Models\Order;
use App\Services\InventoryService;
use App\Services\OrderTimelineService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderManager
{
    /**
     * Allowed status transitions (additive — includes the AliExpress
     * sourcing states awaiting_purchase → ready_to_ship).
     */
    protected const ALLOWED_TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'cancelled', 'awaiting_purchase'],
        'awaiting_purchase' => ['purchased', 'cancelled'],
        'purchased' => ['stock_received', 'cancelled'],
        'stock_received' => ['ready_to_ship', 'cancelled'],
        'ready_to_ship' => ['shipped', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'cancelled'],
        'delivered' => ['completed'],
        'cancelled' => [],
        'completed' => [],
    ];

    protected NotificationManager $notificationManager;

    protected OrderTimelineService $timeline;

    public function __construct(
        NotificationManager $notificationManager,
        OrderTimelineService $timeline,
        protected InventoryService $inventory,
        protected EscrowManager $escrowManager,
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

            // Update transaction status when order completes or cancels
            if (in_array($status, ['completed', 'delivered'])) {
                $order->transaction()?->update(['status' => 'completed']);
            } elseif ($status === 'cancelled') {
                $order->transaction()?->update(['status' => 'cancelled']);

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
