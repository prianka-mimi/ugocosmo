<?php

namespace App\Jobs;

use App\Actions\ReleaseEscrowFundsAction;
use App\Models\AliExpressAccount;
use App\Models\Order;
use App\Services\AliExpressOrderClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAliExpressTrackingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(AliExpressOrderClient $client, ReleaseEscrowFundsAction $releaseFundsAction): void
    {
        // Get all processing/purchased/shipped AE orders
        $orders = Order::whereIn('status', ['processing', 'purchased', 'shipped'])
            ->whereNotNull('aliexpress_order_numbers')
            ->get();

        foreach ($orders as $order) {
            try {
                $aeOrderIds = $order->aliexpress_order_numbers;
                if (empty($aeOrderIds)) {
                    continue;
                }

                // Usually take the first order ID for dropshipping tracking
                $aeOrderId = $aeOrderIds[0];

                // Get the seller's AE account
                $account = AliExpressAccount::byUser($order->seller_id)->active()->latest('connected_at')->first();
                if (! $account) {
                    continue;
                }

                $trackingInfo = $client->getOrderTracking($account, (string) $aeOrderId);

                $hasStatusChangedToShipped = false;
                $hasStatusChangedToDelivered = false;

                // If status indicates shipped or tracking is available
                if ($order->status !== 'shipped' && $order->status !== 'delivered') {
                    if ($trackingInfo['status'] === 'WAIT_BUYER_ACCEPT_GOODS' || $trackingInfo['tracking_number']) {
                        $updates = [
                            'status' => 'shipped',
                        ];

                        if ($trackingInfo['tracking_number']) {
                            $updates['tracking_number'] = $trackingInfo['tracking_number'];
                            $updates['shipped_at'] = now();
                        }

                        $order->update($updates);
                        $hasStatusChangedToShipped = true;
                    }
                }

                // If it is completely finished
                if ($trackingInfo['status'] === 'FINISH' && $order->status !== 'delivered') {
                    $order->update([
                        'status' => 'delivered',
                        'delivered_at' => now(),
                    ]);
                    $hasStatusChangedToDelivered = true;
                }

                if ($hasStatusChangedToDelivered) {
                    $releaseFundsAction->execute($order);
                }

            } catch (\Throwable $e) {
                Log::error("Failed to sync tracking for Order #{$order->id}: ".$e->getMessage());
            }
        }
    }
}
