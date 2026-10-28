<?php

namespace App\Console\Commands;

use App\Jobs\SyncShipmentTrackingJob;
use App\Models\Shipment;
use Illuminate\Console\Command;

/**
 * Dispatches queued tracking sync jobs for shipments that have a tracking
 * number but whose tracking status has not been refreshed recently, and
 * recovers shipments stuck in the transient `purchasing` state.
 */
class SyncShipmentTracking extends Command
{
    protected $signature = 'shipengine:sync-tracking';

    protected $description = 'Dispatch tracking sync jobs for active shipments';

    public function handle(): int
    {
        $staleMinutes = (int) config('services.shipengine.tracking_sync_minutes', 180);

        $this->recoverStalePurchases();

        $shipments = Shipment::query()
            ->whereNotNull('tracking_number')
            ->whereNotNull('carrier_code')
            ->where('voided', false)
            ->whereNull('delivered_at')
            ->where(function ($query) use ($staleMinutes) {
                $query->whereNull('last_tracking_synced_at')
                    ->orWhere('last_tracking_synced_at', '<', now()->subMinutes($staleMinutes));
            })
            ->limit(500)
            ->get();

        foreach ($shipments as $shipment) {
            SyncShipmentTrackingJob::dispatch($shipment);
        }

        $this->info("Dispatched tracking sync for {$shipments->count()} shipment(s).");

        return self::SUCCESS;
    }

    /**
     * A label purchase claim that never completed (e.g. the worker died
     * mid-request) is reset after a grace period so the seller can retry.
     * The 10-minute window prevents a legitimate in-flight purchase from
     * being un-claimed while another request could double-buy.
     */
    protected function recoverStalePurchases(): void
    {
        $graceMinutes = 10;

        $count = Shipment::query()
            ->where('status', 'purchasing')
            ->where('updated_at', '<', now()->subMinutes($graceMinutes))
            ->update(['status' => 'created', 'error_message' => 'Recovered interrupted label purchase.']);

        if ($count > 0) {
            $this->info("Recovered {$count} interrupted label purchase(s).");
        }
    }
}
