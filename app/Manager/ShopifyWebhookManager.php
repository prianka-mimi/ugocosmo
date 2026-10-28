<?php

namespace App\Manager;

use App\DTOs\Shopify\ShopifyProductDTO;
use App\Models\Product;
use App\Models\ShopifyStore;
use App\Models\ShopifyWebhookCall;
use App\Services\ShopifyGraphQLService;
use Illuminate\Support\Facades\Log;

class ShopifyWebhookManager
{
    protected const TOPICS = [
        'products/create',
        'products/update',
        'products/delete',
        'inventory_levels/update',
        'app/uninstalled',
    ];

    /**
     * Maximum attempts before dead-lettering a webhook.
     */
    protected const DEAD_LETTER_MAX_ATTEMPTS = 5;

    public function __construct(
        protected ShopifyGraphQLService $graphQL,
    ) {}

    /**
     * Register all required webhooks for a store.
     */
    public function registerWebhooks(ShopifyStore $store, string $baseUrl): array
    {
        $this->graphQL->forStore($store);
        $results = [];

        foreach (self::TOPICS as $topic) {
            $callbackUrl = rtrim($baseUrl, '/')."/api/webhooks/shopify/{$topic}";
            $result = $this->graphQL->createWebhook($topic, $callbackUrl);

            $results[] = [
                'topic' => $topic,
                'success' => $result && empty($result['userErrors']),
                'errors' => $result['userErrors'] ?? [],
            ];
        }

        return $results;
    }

    /**
     * Verify HMAC signature from an incoming webhook request.
     */
    public function verifyHmac(string $hmacHeader, string $body, string $apiSecret): bool
    {
        $computed = base64_encode(hash_hmac('sha256', $body, $apiSecret, true));

        return hash_equals($computed, $hmacHeader);
    }

    /**
     * Check if a webhook has already been processed (idempotency).
     * Uses X-Shopify-Webhook-Id header for deduplication.
     */
    public function isDuplicate(string $webhookId, string $topic): bool
    {
        if (empty($webhookId)) {
            return false;
        }

        return ShopifyWebhookCall::where('webhook_id', $webhookId)
            ->where('topic', $topic)
            ->whereIn('status', [
                ShopifyWebhookCall::STATUS_PROCESSED,
                ShopifyWebhookCall::STATUS_DUPLICATE,
                ShopifyWebhookCall::STATUS_PROCESSING,
            ])
            ->exists();
    }

    /**
     * Process an incoming webhook event with idempotency + dead-letter support.
     */
    public function processWebhook(
        string $topic,
        string $shopDomain,
        array $payload,
        ?string $webhookId = null,
        ?int $webhookCallId = null,
    ): array {
        // ─── Idempotency check ───────────────────────────────────
        if (! empty($webhookId) && $this->isDuplicate($webhookId, $topic)) {
            Log::info('Shopify webhook: duplicate detected, skipping', [
                'topic' => $topic,
                'shop' => $shopDomain,
                'webhook_id' => $webhookId,
            ]);

            // Mark the existing call as duplicate if we have its ID
            if ($webhookCallId) {
                ShopifyWebhookCall::where('id', $webhookCallId)
                    ->update(['status' => ShopifyWebhookCall::STATUS_DUPLICATE]);
            }

            return ['handled' => false, 'message' => 'Duplicate webhook, skipped', 'duplicate' => true];
        }

        $store = ShopifyStore::where('store_domain', $shopDomain)
            ->whereNull('deleted_at')
            ->first();

        // Find or create the webhook call record
        $webhookCall = null;
        if ($webhookCallId) {
            $webhookCall = ShopifyWebhookCall::find($webhookCallId);
        }

        if (! $webhookCall) {
            $webhookCall = ShopifyWebhookCall::create([
                'shopify_store_id' => $store?->id,
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'webhook_id' => $webhookId,
                'payload' => $payload,
                'status' => ShopifyWebhookCall::STATUS_RECEIVED,
            ]);
        }

        // Mark as processing
        $webhookCall->markProcessing();

        try {
            $result = match ($topic) {
                'products/create' => $this->handleProductCreate($store, $payload),
                'products/update' => $this->handleProductUpdate($store, $payload),
                'products/delete' => $this->handleProductDelete($store, $payload),
                'inventory_levels/update' => $this->handleInventoryUpdate($store, $payload),
                'app/uninstalled' => $this->handleAppUninstalled($store),
                default => ['handled' => false, 'message' => "Unknown topic: {$topic}"],
            };

            $webhookCall->markProcessed();

            return $result;
        } catch (\Exception $e) {
            $webhookCall->markFailed($e->getMessage());

            Log::error("Shopify webhook processing failed: {$topic}: ".$e->getMessage(), [
                'shop' => $shopDomain,
                'attempt' => $webhookCall->processing_attempts,
                'webhook_id' => $webhookId,
            ]);

            // Re-throw only if not dead-lettered (let queue retry)
            if (! $webhookCall->isDeadLettered()) {
                throw $e;
            }

            // Dead-lettered: return without throwing (acknowledge the webhook)
            Log::warning("Shopify webhook dead-lettered after {$webhookCall->processing_attempts} attempts", [
                'topic' => $topic,
                'shop' => $shopDomain,
                'webhook_call_id' => $webhookCall->id,
            ]);

            return ['handled' => false, 'message' => 'Dead-lettered after max attempts', 'dead_lettered' => true];
        }
    }

    /**
     * Handle products/create webhook.
     * Uses composite dedup key: source + shopify_product_id + shopify_store_id.
     */
    protected function handleProductCreate(?ShopifyStore $store, array $payload): array
    {
        if (! $store) {
            return ['handled' => false, 'message' => 'Store not found'];
        }

        $product = $payload;
        $shopifyId = (string) ($product['id'] ?? '');

        // ─── Composite dedup: source + shopify_product_id + shopify_store_id ──
        $existing = Product::where('source', 'shopify')
            ->where('shopify_product_id', $shopifyId)
            ->where('shopify_store_id', $store->id)
            ->exists();

        if ($existing) {
            return $this->handleProductUpdate($store, $payload);
        }

        $variant = $product['variants'][0] ?? [];
        $shopifyUpdatedAt = ! empty($product['updated_at']) ? new \DateTime($product['updated_at']) : null;

        // ─── Build full variant mapping from all variants ────────────
        $dto = ShopifyProductDTO::fromRest($product);
        $localVariants = $dto->buildLocalVariants();

        $localProduct = Product::create([
            'seller_id' => $store->profile_id,
            'title' => $product['title'] ?? '',
            'description' => strip_tags($product['body_html'] ?? ''),
            'price' => (float) ($variant['price'] ?? 0),
            'compare_at_price' => ! empty($variant['compare_at_price']) ? (float) $variant['compare_at_price'] : null,
            'stock_quantity' => (int) ($variant['inventory_quantity'] ?? 0),
            'product_type' => $product['product_type'] ?? null,
            'brand' => $product['vendor'] ?? null,
            'sku' => $variant['sku'] ?? null,
            'weight' => (float) ($variant['weight'] ?? 0),
            'is_active' => ($product['status'] ?? 'active') === 'active',
            'shopify_product_id' => $shopifyId,
            'shopify_variant_id' => (string) ($variant['id'] ?? ''),
            'shopify_inventory_item_id' => (string) ($variant['inventory_item_id'] ?? ''),
            'shopify_store_url' => $store->store_domain,
            'shopify_store_id' => $store->id,
            'shopify_updated_at' => $shopifyUpdatedAt,
            'source' => 'shopify',
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            // ── Full variant mapping ─────────────────────────────────
            'product_variants' => ! empty($localVariants) ? $localVariants : null,
            // ── SEO & Metadata ───────────────────────────────────────
            'shopify_source_data' => [
                'webhook_created' => true,
                'handle' => $product['handle'] ?? null,
                'seo_title' => $product['seo_title'] ?? $product['metafields_global_title_tag'] ?? null,
                'seo_description' => $product['seo_description'] ?? $product['metafields_global_description_tag'] ?? null,
                'options' => $dto->options,
                'imported_at' => now()->toIso8601String(),
            ],
        ]);

        return [
            'handled' => true,
            'action' => 'created',
            'local_product_id' => $localProduct->id,
        ];
    }

    /**
     * Handle products/update webhook.
     * Uses composite dedup key: source + shopify_product_id + shopify_store_id.
     * Respects price sync policy from the store configuration.
     */
    protected function handleProductUpdate(?ShopifyStore $store, array $payload): array
    {
        if (! $store) {
            return ['handled' => false, 'message' => 'Store not found'];
        }

        $product = $payload;
        $shopifyId = (string) ($product['id'] ?? '');
        $variant = $product['variants'][0] ?? [];

        // ─── Composite dedup: source + shopify_product_id + shopify_store_id ──
        $localProduct = Product::where('source', 'shopify')
            ->where('shopify_product_id', $shopifyId)
            ->where('shopify_store_id', $store->id)
            ->first();

        if (! $localProduct) {
            return $this->handleProductCreate($store, $payload);
        }

        $shopifyUpdatedAt = ! empty($product['updated_at']) ? new \DateTime($product['updated_at']) : null;

        // ─── Build full variant mapping ─────────────────────────────
        $dto = ShopifyProductDTO::fromRest($product);
        $localVariants = $dto->buildLocalVariants();

        $updateData = [
            'title' => $product['title'] ?? $localProduct->title,
            'description' => strip_tags($product['body_html'] ?? $localProduct->description),
            'stock_quantity' => (int) ($variant['inventory_quantity'] ?? $localProduct->stock_quantity),
            'sku' => $variant['sku'] ?? $localProduct->sku,
            'weight' => (float) ($variant['weight'] ?? $localProduct->weight),
            'is_active' => ($product['status'] ?? 'active') === 'active',
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            'shopify_updated_at' => $shopifyUpdatedAt,
            // ── Full variant mapping ─────────────────────────────────
            'product_variants' => ! empty($localVariants) ? $localVariants : $localProduct->product_variants,
        ];

        // ── Apply price sync policy ─────────────────────────────────
        $shopifyPrice = (float) ($variant['price'] ?? 0);
        $shopifyCompareAt = ! empty($variant['compare_at_price']) ? (float) $variant['compare_at_price'] : null;

        if ($store->shouldSyncPrice($shopifyPrice, (float) $localProduct->price)) {
            $updateData['price'] = $shopifyPrice;
            $updateData['compare_at_price'] = $shopifyCompareAt;
        }

        $localProduct->update($updateData);

        return [
            'handled' => true,
            'action' => 'updated',
            'local_product_id' => $localProduct->id,
        ];
    }

    /**
     * Handle products/delete webhook.
     * Uses composite dedup key: source + shopify_product_id + shopify_store_id.
     */
    protected function handleProductDelete(?ShopifyStore $store, array $payload): array
    {
        if (! $store) {
            return ['handled' => false, 'message' => 'Store not found'];
        }

        $shopifyId = (string) ($payload['id'] ?? '');

        $localProduct = Product::where('source', 'shopify')
            ->where('shopify_product_id', $shopifyId)
            ->where('shopify_store_id', $store->id)
            ->first();

        if ($localProduct) {
            $localProduct->update([
                'is_active' => false,
                'sync_status' => 'deleted_on_shopify',
                'last_synced_at' => now(),
            ]);
        }

        return [
            'handled' => true,
            'action' => 'deactivated',
            'local_product_id' => $localProduct?->id,
        ];
    }

    /**
     * Handle inventory_levels/update webhook.
     */
    protected function handleInventoryUpdate(?ShopifyStore $store, array $payload): array
    {
        if (! $store) {
            return ['handled' => false, 'message' => 'Store not found'];
        }

        $inventoryItemId = (string) ($payload['inventory_item_id'] ?? '');
        $available = (int) ($payload['available'] ?? 0);

        $localProduct = Product::where('source', 'shopify')
            ->where('shopify_inventory_item_id', $inventoryItemId)
            ->where('shopify_store_id', $store->id)
            ->first();

        if ($localProduct) {
            $localProduct->update([
                'stock_quantity' => $available,
                'sync_status' => 'synced',
                'last_synced_at' => now(),
            ]);
        }

        return [
            'handled' => true,
            'action' => 'inventory_updated',
            'local_product_id' => $localProduct?->id,
            'available' => $available,
        ];
    }

    /**
     * Handle app/uninstalled webhook — soft disconnect the store.
     */
    protected function handleAppUninstalled(?ShopifyStore $store): array
    {
        if (! $store) {
            return ['handled' => false, 'message' => 'Store not found'];
        }

        $store->update([
            'status' => 'disconnected',
            'deleted_at' => now(),
        ]);

        return [
            'handled' => true,
            'action' => 'store_disconnected',
        ];
    }
}
