<?php

namespace App\Http\Controllers;

use App\Jobs\ShopifyWebhookProcessJob;
use App\Manager\ShopifyWebhookManager;
use App\Models\ShopifyWebhookCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookController extends Controller
{
    public function __construct(
        protected ShopifyWebhookManager $webhookManager,
    ) {}

    /**
     * Handle incoming Shopify webhooks.
     *
     * POST /api/webhooks/shopify/{topic}
     *
     * Shopify sends webhook events to this endpoint.
     * Supports: products/create, products/update, products/delete,
     *           inventory_levels/update, app/uninstalled
     *
     * Idempotency: Uses X-Shopify-Webhook-Id header to prevent duplicate processing.
     * Dead-letter: After 5 processing attempts, webhooks are moved to dead letter.
     */
    public function handle(Request $request, string $topic): JsonResponse
    {
        try {
            $payload = $request->getContent();
            $hmacHeader = $request->header('X-Shopify-Hmac-Sha256');
            $shopDomain = $request->header('X-Shopify-Shop-Domain');
            $webhookId = $request->header('X-Shopify-Webhook-Id');

            if (! $hmacHeader || ! $shopDomain) {
                Log::warning('Shopify webhook: missing HMAC header or shop domain');

                return response()->json(['error' => 'Invalid webhook headers'], 401);
            }

            // Verify HMAC
            $apiSecret = config('services.shopify.api_secret');
            if (! $this->webhookManager->verifyHmac($hmacHeader, $payload, $apiSecret)) {
                Log::warning('Shopify webhook: HMAC verification failed', [
                    'topic' => $topic,
                    'shop' => $shopDomain,
                ]);

                return response()->json(['error' => 'HMAC verification failed'], 401);
            }

            $data = json_decode($payload, true);

            // ─── Idempotency check ──────────────────────────────────
            if (! empty($webhookId) && $this->webhookManager->isDuplicate($webhookId, $topic)) {
                Log::info('Shopify webhook: duplicate detected via webhook ID, acknowledged', [
                    'topic' => $topic,
                    'shop' => $shopDomain,
                    'webhook_id' => $webhookId,
                ]);

                // Always return 200 to Shopify (acknowledge receipt)
                return response()->json(['success' => true, 'duplicate' => true], 200);
            }

            // Create webhook call record with idempotency key
            $webhookCall = ShopifyWebhookCall::create([
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'webhook_id' => $webhookId,
                'payload' => $data,
                'status' => ShopifyWebhookCall::STATUS_RECEIVED,
            ]);

            // Dispatch async processing with webhook ID for idempotency
            ShopifyWebhookProcessJob::dispatch(
                topic: $topic,
                shopDomain: $shopDomain,
                payload: $data,
                webhookCallId: $webhookCall->id,
                webhookId: $webhookId,
            );

            Log::info('Shopify webhook received', [
                'topic' => $topic,
                'shop' => $shopDomain,
                'webhook_id' => $webhookId,
                'webhook_call_id' => $webhookCall->id,
            ]);

            return response()->json(['success' => true], 200);
        } catch (\Exception $e) {
            Log::error('Shopify webhook handler error: '.$e->getMessage());

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Handle app/uninstalled webhook specifically.
     */
    public function handleAppUninstalled(Request $request): JsonResponse
    {
        return $this->handle($request, 'app/uninstalled');
    }

    /**
     * Handle inventory_levels/update webhook.
     */
    public function handleInventoryUpdate(Request $request): JsonResponse
    {
        return $this->handle($request, 'inventory_levels/update');
    }
}
