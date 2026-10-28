<?php

namespace App\Services;

use App\Models\ShipEngineWebhookCall;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ShipStation (formerly ShipEngine) tracking webhook processing (idempotent).
 *
 * Flow: webhook → RSA-SHA256 signature verification (ShipstationWebhookVerifier)
 * → dispatch ProcessShipEngineWebhookJob → this service → ShipmentService
 * (the same tracking pipeline the manual refresh uses) → DB → notifications.
 *
 * Idempotency layers:
 *  1. Webhook ledger — ShipStation redelivers at-least-once; each delivery
 *     is recorded (resource_token + event_type). Identical redeliveries
 *     short-circuit before any mutation.
 *  2. Event rows — ShipmentTrackingEvent dedupes by external event id /
 *     tuple, so poll + webhook overlap never duplicates history.
 *  3. Forward-only status — a stale event can never move a shipment
 *     backwards (in_transit after delivered is rejected).
 *
 * Payload support: the official ShipStation schema uses resource_type
 * ("API_TRACK") and data; the legacy ShipEngine schema used resource and
 * meta.event — both are accepted so existing integrations keep working.
 */
class ShipEngineWebhookService
{
    public function __construct(
        protected ShipmentService $shipments,
    ) {}

    /**
     * Handle a tracking webhook body.
     *
     * @param  string  $rawBody  raw request content (unused server-side but
     *                           kept for the signature contract and logging)
     * @param  array<string, mixed>  $payload  decoded JSON body
     * @param  string  $dedupeToken  idempotency token (uuid or body hash)
     * @param  string  $eventType  the resource_type (e.g. "API_TRACK")
     * @return array{processed: bool, duplicate: bool, shipment_id: ?int}
     */
    public function handleTrackingPayload(string $rawBody, array $payload, string $dedupeToken, string $eventType): array
    {
        // ── Ledger check: identical redelivery → no-op ─────────────────
        $existing = ShipEngineWebhookCall::query()
            ->where('resource_token', $dedupeToken)
            ->where('event_type', $eventType)
            ->first();

        if ($existing !== null) {
            Log::channel('shipengine')->info('ShipStation webhook duplicate ignored', [
                'resource_token' => $dedupeToken,
                'event_type' => $eventType,
            ]);

            return ['processed' => true, 'duplicate' => true, 'shipment_id' => null];
        }

        $shipment = $this->identifyShipment($payload);

        // Record the delivery even when the shipment can't be matched (e.g.
        // a webhook for a voided/unknown label) so it is never reprocessed.
        ShipEngineWebhookCall::query()->create([
            'resource_token' => $dedupeToken,
            'event_type' => $eventType,
            'payload' => $payload,
            'processed_at' => now(),
        ]);

        if ($shipment === null) {
            Log::channel('shipengine')->warning('ShipStation webhook: no matching shipment', [
                'resource_token' => $dedupeToken,
                'event_type' => $eventType,
            ]);

            return ['processed' => false, 'duplicate' => false, 'shipment_id' => null];
        }

        // ── Extract tracking data from the official `data` field ──────
        // Support the legacy `resource` field as a fallback for older payloads.
        $resource = $this->extractTrackingResource($payload);

        try {
            if (is_array($resource) && isset($resource['tracking_number'])) {
                $this->shipments->processTrackingResponse($shipment, $resource);
            } else {
                // Resource URL-only payload: fall back to a synchronous
                // tracking fetch (same data, single external call).
                $this->shipments->syncTrackingStatus($shipment);
            }
        } catch (Throwable $e) {
            // Clear the ledger row so ShipStation's redelivery (on HTTP 500)
            // is allowed to retry the processing. Event-level dedupe in
            // ShipmentTrackingEvent keeps history clean either way.
            ShipEngineWebhookCall::query()
                ->where('resource_token', $dedupeToken)
                ->where('event_type', $eventType)
                ->delete();

            Log::channel('shipengine')->error('ShipStation webhook processing failed', [
                'shipment_id' => $shipment->id,
                'resource_token' => $dedupeToken,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return ['processed' => true, 'duplicate' => false, 'shipment_id' => $shipment->id];
    }

    /**
     * Extract the tracking resource data from the webhook payload.
     *
     * Official ShipStation schema: data field contains the tracking details.
     * Legacy ShipEngine schema: resource field contained the same data.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function extractTrackingResource(array $payload): array
    {
        $data = $payload['data'] ?? null;

        if (is_array($data)) {
            return array_merge($data, [
                'resource_url' => $payload['resource_url'] ?? null,
            ]);
        }

        $resource = $payload['resource'] ?? null;

        if (is_array($resource)) {
            return $resource;
        }

        if (isset($payload['tracking_number']) || isset($payload['status_code'])) {
            return $payload;
        }

        return [];
    }

    /**
     * Find the shipment a webhook refers to: tracking number first, then
     * label id / shipment id in the resource data.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function identifyShipment(array $payload): ?Shipment
    {
        $resource = $this->extractTrackingResource($payload);

        $trackingNumber = $resource['tracking_number'] ?? null;

        if ($trackingNumber !== null && $trackingNumber !== '') {
            $shipment = Shipment::query()->where('tracking_number', (string) $trackingNumber)->first();
            if ($shipment !== null) {
                return $shipment;
            }
        }

        $labelId = $resource['label_id'] ?? null;
        if ($labelId !== null && $labelId !== '') {
            $shipment = Shipment::query()->where('external_label_id', (string) $labelId)->first();
            if ($shipment !== null) {
                return $shipment;
            }
        }

        $shipmentId = $resource['shipment_id'] ?? null;
        if ($shipmentId !== null && $shipmentId !== '') {
            return Shipment::query()->where('external_shipment_id', (string) $shipmentId)->first();
        }

        return null;
    }
}
