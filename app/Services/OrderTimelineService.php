<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderTimelineEvent;

/**
 * Records every fulfillment milestone on an order as an immutable
 * timeline event (status history with timestamps).
 *
 * Single entry point used by every status writer:
 *  - OrderManager (seller transitions + buyer delivery confirmation)
 *  - CheckoutManager (order placement)
 *  - Admin web panel (AdminOrderController, AdminDisputeController)
 */
class OrderTimelineService
{
    public const ACTOR_BUYER = 'buyer';

    public const ACTOR_SELLER = 'seller';

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_SYSTEM = 'system';

    /**
     * Record a milestone event.
     */
    public function record(
        Order $order,
        string $status,
        ?int $actorProfileId = null,
        string $actorRole = self::ACTOR_SYSTEM,
        ?string $notes = null,
        ?array $metadata = null,
    ): OrderTimelineEvent {
        return $order->timelineEvents()->create([
            'status' => $status,
            'actor_role' => $actorRole,
            'actor_profile_id' => $actorProfileId,
            'notes' => $notes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
