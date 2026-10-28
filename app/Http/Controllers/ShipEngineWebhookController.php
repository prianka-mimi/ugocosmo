<?php

namespace App\Http\Controllers;

use App\Exceptions\ShipEngine\ShipstationWebhookVerificationException;
use App\Jobs\ProcessShipEngineTrackingJob;
use App\Models\ShipEngineWebhookCall;
use App\Support\ShipstationWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ShipStation (formerly ShipEngine) tracking webhook receiver.
 *
 * POST /api/webhooks/shipengine/tracking  (public — ShipStation calls this
 * directly, no JWT auth; authenticated by RSA-SHA256 signature verification,
 * mirroring the existing Stripe/Shopify webhook controllers).
 *
 * HTTP response contract (per official ShipStation webhook docs):
 *   - Missing required signature headers (RSA-SHA256):  HTTP 404
 *   - Invalid / rejected timestamp (replay):           HTTP 400
 *   - Invalid signature:                               HTTP 401
 *   - Valid signature + malformed JSON:                HTTP 400
 *   - Valid signature + unhandled resource type:       HTTP 200 (ignored)
 *   - Valid signature + supported tracking event:        HTTP 200 (queued)
 *
 * Heavy business processing is always dispatched to a queue job so the
 * provider receives an immediate 2xx and can retry on transient failures.
 */
class ShipEngineWebhookController extends Controller
{
    public function __construct(
        protected ShipstationWebhookVerifier $verifier,
    ) {}

    public function tracking(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        // Normalise request headers to a lowercased bag (HTTP headers are
        // case-insensitive but PHP's $request->headers is not always predictable).
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = $values[0] ?? null;
        }

        try {
            $this->verifier->verify($rawBody, $headers);
        } catch (ShipstationWebhookVerificationException $e) {
            Log::channel('shipengine')->info('ShipEngine webhook signature rejected', [
                'status' => $e->verificationStatus,
            ]);

            return response()->json(['status' => 'error', 'status_message' => $e->getMessage()], $e->verificationStatus);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return response()->json(['status' => 'error', 'status_message' => 'Invalid payload'], 400);
        }

        // Accept resource_type, event, or meta.event for tracking payloads
        $resourceType = (string) ($payload['resource_type'] ?? $payload['event'] ?? ($payload['meta']['event'] ?? ''));

        $isTracking = in_array(strtolower($resourceType), [
            'api_track',
            'track',
            'track.updated',
            'tracking.updated',
            'batch.process_completed',
        ], true) || str_contains(strtolower($resourceType), 'track');

        if (! $isTracking) {
            Log::channel('shipengine')->info('ShipEngine webhook ignored unsupported resource type', [
                'resource_type' => $resourceType,
            ]);

            return response()->json(['status' => 'success', 'status_message' => 'Webhook received but unsupported resource type.'], 200);
        }

        // The idempotency token: ShipStation includes a unique webhook identifier
        // (uuid) or we fall back to a hash of the raw body (identical redelivery
        // → same hash). The ledger check below is a fast DB query that returns
        // the duplicate flag without dispatching a job for redeliveries.
        $dedupeToken = (string) ($payload['uuid'] ?? 'sha256:'.hash('sha256', $rawBody));

        $isDuplicate = ShipEngineWebhookCall::query()
            ->where('resource_token', $dedupeToken)
            ->where('event_type', $resourceType)
            ->exists();

        if ($isDuplicate) {
            Log::channel('shipengine')->info('ShipEngine webhook duplicate short-circuited', [
                'resource_token' => $dedupeToken,
                'event_type' => $resourceType,
            ]);

            return response()->json([
                'status' => 'success',
                'status_message' => 'Webhook received (duplicate).',
                'duplicate' => true,
            ], 200);
        }

        // Dispatch to the queue — the provider gets a fast 2xx and retries
        // on failure. Idempotency is enforced server-side (ledger + event dedupe).
        try {
            ProcessShipEngineTrackingJob::dispatch($payload, $dedupeToken, $resourceType);
        } catch (Throwable $e) {
            // With sync queue (tests), the job runs inline and exceptions
            // propagate here. Return 500 so ShipStation redelivers — the
            // idempotency ledger prevents duplicate state transitions.
            Log::channel('shipengine')->error('ShipEngine webhook processing failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'status_message' => 'Processing failed, retry later.'], 500);
        }

        return response()->json([
            'status' => 'success',
            'status_message' => 'Webhook received and queued for processing.',
            'duplicate' => false,
        ], 200);
    }
}
