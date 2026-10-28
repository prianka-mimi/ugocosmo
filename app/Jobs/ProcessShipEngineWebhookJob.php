<?php

namespace App\Jobs;

/**
 * Backward-compatible alias / extension of ProcessShipEngineTrackingJob.
 *
 * Dispatched by ShipEngineWebhookController; all queue processing logic
 * is implemented in App\Jobs\ProcessShipEngineTrackingJob.
 */
class ProcessShipEngineWebhookJob extends ProcessShipEngineTrackingJob
{
    // Inherits all queue handling and processing from ProcessShipEngineTrackingJob.
}
