<?php

namespace App\Jobs;

use App\Services\ShipEngineWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes a verified ShipEngine / ShipStation tracking event asynchronously on the queue.
 *
 * Dispatched by ShipEngineWebhookController AFTER signature verification passes.
 * Resolves the shipment by tracking_number (or label/shipment id), records the
 * idempotency ledger entry, persists tracking events, updates shipment carriage status,
 * and synchronizes the parent Order status and timeline.
 */
class ProcessShipEngineTrackingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly array $payload,
        public readonly string $dedupeToken = '',
        public readonly string $eventType = 'API_TRACK',
    ) {}

    /**
     * Backoff strategy: exponential backoff starting at 5s, capped at 60s.
     */
    public function backoff(): array
    {
        return [5, 15, 30, 60, 60];
    }

    public function handle(ShipEngineWebhookService $webhooks): void
    {
        $token = $this->dedupeToken !== '' ? $this->dedupeToken : (string) ($this->payload['uuid'] ?? ('job:'.uniqid('', true)));

        try {
            $webhooks->handleTrackingPayload('', $this->payload, $token, $this->eventType);
        } catch (Throwable $e) {
            Log::channel('shipengine')->error('ProcessShipEngineTrackingJob failed', [
                'event_type' => $this->eventType,
                'dedupe_token' => $token,
                'error' => $e->getMessage(),
                'tries' => $this->attempts(),
            ]);

            throw $e;
        }
    }
}
