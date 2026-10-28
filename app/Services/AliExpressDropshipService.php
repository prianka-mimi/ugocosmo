<?php

namespace App\Services;

use App\Manager\EscrowManager;
use App\Manager\NotificationManager;
use App\Models\AliExpressAccount;
use App\Models\EscrowPayment;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AliExpressDropshipService — synchronises supplier shipment tracking from
 * AliExpress and auto-manages the local order lifecycle + escrow release.
 *
 * AliExpress order statuses (aliexpress.trade.ds.order.get):
 *   PLACE_ORDER_SUCCESS     — Order placed, awaiting seller shipment
 *   WAIT_SELLER_SEND_GOODS  — Waiting for seller to ship
 *   SELLER_PART_SEND_GOODS  — Partially shipped
 *   WAIT_BUYER_ACCEPT_GOODS — Shipped, in transit to buyer
 *   FUND_PROCESSING         — Payment being processed
 *   FINISH                  — Buyer received & order complete
 *   IN_CANCEL               — Cancellation in progress
 *   CANCELLED               — Order cancelled
 *
 * When AliExpress says "WAIT_BUYER_ACCEPT_GOODS" → local order → shipped
 * When AliExpress says "FINISH"                  → local order → delivered → completed
 *                                                → escrow auto-released → payout created
 */
class AliExpressDropshipService
{
    /**
     * AliExpress statuses meaning the product is in transit / shipped.
     */
    protected const SHIPPED_STATUSES = [
        'WAIT_BUYER_ACCEPT_GOODS',
        'SELLER_PART_SEND_GOODS',
    ];

    /**
     * AliExpress statuses meaning the buyer has received the product.
     */
    protected const DELIVERED_STATUSES = [
        'FINISH',
    ];

    /**
     * AliExpress statuses meaning the order is cancelled.
     */
    protected const CANCELLED_STATUSES = [
        'CANCELLED',
        'IN_CANCEL',
    ];

    public function __construct(
        protected AliExpressOrderClient $orderClient,
        protected EscrowManager $escrowManager,
        protected NotificationManager $notificationManager,
    ) {}

    /**
     * Sync tracking for all placed AliExpress orders belonging to a seller account.
     *
     * @return array{checked: int, shipped: int, delivered: int, failed: int}
     */
    public function syncTrackingForAccount(AliExpressAccount $account): array
    {
        $summary = ['checked' => 0, 'shipped' => 0, 'delivered' => 0, 'failed' => 0];

        // Find all orders that were placed on AliExpress and are not yet completed/cancelled
        $orders = Order::query()
            ->whereNotNull('aliexpress_order_numbers')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where('seller_id', function ($q) use ($account) {
                $q->select('id')
                    ->from('profiles')
                    ->where('user_id', $account->user_id);
            })
            ->limit(200)
            ->get();

        foreach ($orders as $order) {
            $aeOrderNumbers = $order->aliexpress_order_numbers;

            if (empty($aeOrderNumbers) || ! is_array($aeOrderNumbers)) {
                continue;
            }

            foreach ($aeOrderNumbers as $aeOrderId) {
                $summary['checked']++;

                try {
                    $tracking = $this->orderClient->getOrderTracking($account, (string) $aeOrderId);
                    $aeStatus = strtoupper(trim($tracking['status'] ?? ''));
                    $trackingNumber = $tracking['tracking_number'] ?? null;

                    // ── SHIPPED ────────────────────────────────────────────
                    if (in_array($aeStatus, self::SHIPPED_STATUSES, true)) {
                        if (in_array($order->status, ['processing', 'awaiting_purchase', 'purchased', 'stock_received', 'ready_to_ship'], true)) {
                            $this->transitionToShipped($order, $trackingNumber, $aeStatus);
                            $summary['shipped']++;
                        }
                    }

                    // ── DELIVERED / FINISHED ───────────────────────────────
                    elseif (in_array($aeStatus, self::DELIVERED_STATUSES, true)) {
                        if (in_array($order->status, ['processing', 'shipped', 'awaiting_purchase', 'purchased', 'stock_received', 'ready_to_ship'], true)) {
                            // First ensure we go through shipped if not already
                            if ($order->status !== 'shipped') {
                                $this->transitionToShipped($order, $trackingNumber, $aeStatus);
                            }

                            $this->transitionToDelivered($order, $aeStatus);
                            $summary['delivered']++;
                        }
                    }

                    // ── CANCELLED ──────────────────────────────────────────
                    elseif (in_array($aeStatus, self::CANCELLED_STATUSES, true)) {
                        if (! in_array($order->status, ['cancelled', 'completed'], true)) {
                            $this->transitionToCancelled($order, $aeStatus);
                        }
                    }

                    // Store latest logistics info on the order
                    $this->updateAliExpressLogistics($order, $aeOrderId, $tracking, $aeStatus);

                } catch (\Throwable $e) {
                    $summary['failed']++;
                    Log::warning("AliExpress tracking sync failed for AE order #{$aeOrderId} (local #{$order->id}): ".$e->getMessage());
                }
            }
        }

        return $summary;
    }

    /**
     * Transition local order to "shipped" and store tracking number.
     */
    protected function transitionToShipped(Order $order, ?string $trackingNumber, string $aeStatus): void
    {
        DB::transaction(function () use ($order, $trackingNumber, $aeStatus) {
            $order->update([
                'status' => 'shipped',
                'shipped_at' => now(),
                'tracking_number' => $trackingNumber ?? $order->tracking_number,
            ]);

            $this->notificationManager->dispatch(
                (int) $order->buyer_id,
                'store.order_updated',
                'Order Shipped',
                "Your order #{$order->id} has been shipped.".($trackingNumber ? " Tracking: {$trackingNumber}" : ''),
                ['order_id' => $order->id, 'status' => 'shipped', 'tracking_number' => $trackingNumber]
            );

            Log::info("AliExpress order #{$order->id} transitioned to shipped (AE status: {$aeStatus}, tracking: {$trackingNumber}).");
        });
    }

    /**
     * Transition local order to "delivered" → "completed", and auto-release escrow.
     */
    protected function transitionToDelivered(Order $order, string $aeStatus): void
    {
        DB::transaction(function () use ($order, $aeStatus) {
            // shipped → delivered
            $order->update([
                'status' => 'delivered',
                'delivered_at' => now(),
            ]);

            // delivered → completed
            $order->update(['status' => 'completed']);
            $order->transaction?->update(['status' => 'completed']);

            $this->notificationManager->dispatch(
                (int) $order->buyer_id,
                'store.order_updated',
                'Order Delivered',
                "Your order #{$order->id} has been delivered and completed.",
                ['order_id' => $order->id, 'status' => 'completed']
            );

            Log::info("AliExpress order #{$order->id} transitioned to delivered/completed (AE status: {$aeStatus}).");

            // ── Auto-release escrow ───────────────────────────────────
            // AliExpress tracking confirmed delivery. Release the held
            // payment to the seller automatically — no buyer action needed.
            $escrow = EscrowPayment::where('order_id', $order->id)
                ->where('status', 'held')
                ->first();

            if ($escrow) {
                try {
                    $this->escrowManager->releaseAsSystem($escrow, (int) $order->seller_id);
                    Log::info("Escrow #{$escrow->id} auto-released for order #{$order->id} after AliExpress delivery confirmation.");

                    // Notify seller that payout is pending
                    $this->notificationManager->dispatch(
                        (int) $order->seller_id,
                        'store.payout_ready',
                        'Payment Released',
                        "Payment of \${$escrow->amount} for order #{$order->id} has been released. Payout is being processed.",
                        ['order_id' => $order->id, 'escrow_id' => $escrow->id, 'amount' => $escrow->amount]
                    );
                } catch (\Throwable $e) {
                    Log::error("Auto escrow release failed for order #{$order->id}: ".$e->getMessage());
                }
            }
        });
    }

    /**
     * Transition local order to "cancelled" when AliExpress cancels.
     */
    protected function transitionToCancelled(Order $order, string $aeStatus): void
    {
        DB::transaction(function () use ($order, $aeStatus) {
            $order->update(['status' => 'cancelled']);
            $order->transaction?->update(['status' => 'cancelled']);

            // Cancel escrow if it was held
            $escrow = EscrowPayment::where('order_id', $order->id)
                ->whereIn('status', ['pending', 'held'])
                ->first();

            if ($escrow) {
                $escrow->update(['status' => 'cancelled']);
                Log::info("Escrow #{$escrow->id} cancelled for AliExpress-cancelled order #{$order->id}.");
            }

            $this->notificationManager->dispatch(
                (int) $order->buyer_id,
                'store.order_updated',
                'Order Cancelled',
                "Your order #{$order->id} has been cancelled by the AliExpress supplier.",
                ['order_id' => $order->id, 'status' => 'cancelled']
            );

            Log::info("AliExpress order #{$order->id} cancelled (AE status: {$aeStatus}).");
        });
    }

    /**
     * Persist latest AliExpress logistics info on the order for reference.
     */
    protected function updateAliExpressLogistics(Order $order, string $aeOrderId, array $tracking, string $aeStatus): void
    {
        $logistics = $order->aliexpress_logistics ?? [];

        $logistics[$aeOrderId] = [
            'ae_status' => $aeStatus,
            'tracking_number' => $tracking['tracking_number'] ?? null,
            'last_synced_at' => now()->toIso8601String(),
        ];

        $order->updateQuietly(['aliexpress_logistics' => $logistics]);
    }
}
