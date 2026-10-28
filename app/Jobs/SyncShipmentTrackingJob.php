<?php

namespace App\Jobs;

use App\Models\Shipment;
use App\Services\ShipmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refresh tracking status for one shipment in the background.
 *
 * Dispatched by the `shipengine:sync-tracking` command for shipments whose
 * tracking is stale. Failures are logged (sanitized) and swallowed — a
 * transient tracking outage must never take down the queue.
 */
class SyncShipmentTrackingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly Shipment $shipment) {}

    /**
     * Backoff strategy: exponential backoff (5s, 15s, 30s).
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(ShipmentService $service): void
    {
        try {
            $service->syncTrackingStatus($this->shipment);
        } catch (Throwable $e) {
            Log::channel('shipengine')->warning('Shipment tracking sync failed', [
                'shipment_id' => $this->shipment->id,
                'order_id' => $this->shipment->order_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
