<?php

namespace App\Manager;

use App\DTOs\Shopify\ShopifyProductDTO;
use App\Models\Product;
use App\Models\ShopifyStore;
use App\Models\ShopifySyncState;
use App\Services\ShopifyGraphQLService;
use App\Services\ShopifyService;

class ShopifySyncManager
{
    public function __construct(
        protected ShopifyGraphQLService $graphQL,
        protected ShopifyService $restService,
        protected ProductManager $productManager,
    ) {}

    /**
     * Sync all active stores.
     */
    public function syncAll(): array
    {
        $stores = ShopifyStore::where('status', 'active')
            ->whereNull('deleted_at')
            ->get();

        $results = [];
        foreach ($stores as $store) {
            $results[] = $this->syncStore($store);
        }

        return $results;
    }

    /**
     * Sync a single store's products using incremental sync.
     *
     * Uses updated_at timestamp to only fetch products that have changed
     * since the last sync. Falls back to full sync if no previous state.
     */
    public function syncStore(ShopifyStore $store): array
    {
        $this->graphQL->forStore($store);
        $this->restService->forStore($store);

        $state = ShopifySyncState::firstOrCreate(
            ['shopify_store_id' => $store->id, 'resource_type' => 'products'],
            [
                'last_synced_at' => now(),
                'last_shopify_id' => null,
                'last_updated_at' => null,
                'total_count' => 0,
            ]
        );

        $updated = 0;
        $created = 0;
        $deactivated = 0;
        $errors = [];

        $hasMore = true;
        $cursor = null;
        $pagesFetched = 0;

        // ─── Incremental sync: only fetch products updated since last sync ──
        $lastUpdatedAt = $state->last_updated_at;

        while ($hasMore) {
            if ($lastUpdatedAt) {
                // Incremental sync using updated_at
                $result = $this->graphQL->fetchProductsUpdatedSince(
                    $lastUpdatedAt->toIso8601String(),
                    50,
                    $cursor
                );
            } else {
                // Full initial sync
                $result = $this->graphQL->fetchProducts(50, $cursor);
            }

            if (! $result) {
                $errors[] = 'Failed to fetch products from Shopify';
                break;
            }

            foreach ($result['products'] as $node) {
                try {
                    $productResult = $this->syncProduct($store, $node);
                    $updated += $productResult['updated'] ? 1 : 0;
                    $created += $productResult['created'] ? 1 : 0;
                    if ($productResult['deactivated']) {
                        $deactivated++;
                    }
                } catch (\Exception $e) {
                    $errors[] = 'Product '.($node['title'] ?? 'unknown').': '.$e->getMessage();
                }
            }

            $cursor = $result['end_cursor'];
            $hasMore = $result['has_next_page'];

            // Safety limit: break after 100 pages (5000 products)
            $pagesFetched++;
            if ($pagesFetched > 100) {
                $errors[] = 'Reached safety limit of 100 pages for single sync operation.';
                break;
            }
        }

        // Update sync state with current timestamp
        $state->update([
            'last_synced_at' => now(),
            'last_updated_at' => now(),
            'last_shopify_id' => $cursor,
            'total_count' => $this->graphQL->countProducts(),
        ]);

        $store->update(['last_sync_at' => now()]);

        return [
            'store_id' => $store->id,
            'store_domain' => $store->store_domain,
            'created' => $created,
            'updated' => $updated,
            'deactivated' => $deactivated,
            'errors' => $errors,
            'error_count' => count($errors),
        ];
    }

    /**
     * Sync a single product from a GraphQL node.
     * Uses composite dedup key: source + shopify_product_id + shopify_store_id.
     */
    public function syncProduct(ShopifyStore $store, array $node): array
    {
        $shopifyId = $this->extractId($node['id']);
        $variantNodes = $node['variants']['edges'] ?? [];
        $firstVariant = $variantNodes[0]['node'] ?? $node['variants'][0] ?? [];

        // ─── Composite dedup: source + shopify_product_id + shopify_store_id ──
        $existingProduct = Product::where('source', 'shopify')
            ->where('shopify_product_id', $shopifyId)
            ->where('shopify_store_id', $store->id)
            ->first();

        $shopifyUpdatedAt = null;
        if (! empty($node['updatedAt'])) {
            $shopifyUpdatedAt = new \DateTime($node['updatedAt']);
        }

        // ─── Build full variant mapping ─────────────────────────────
        $dto = ShopifyProductDTO::fromGraphQL($node);
        $localVariants = $dto->buildLocalVariants();

        // ─── SEO ────────────────────────────────────────────────────
        $seo = $node['seo'] ?? [];

        $price = (float) ($firstVariant['price'] ?? 0);
        $compareAt = ! empty($firstVariant['compareAtPrice']) ? (float) $firstVariant['compareAtPrice'] : null;

        $productData = [
            'seller_id' => $store->profile_id,
            'title' => $node['title'] ?? '',
            'description' => strip_tags($node['descriptionHtml'] ?? ''),
            'stock_quantity' => (int) ($firstVariant['inventoryQuantity'] ?? 0),
            'product_type' => $node['productType'] ?? null,
            'brand' => $node['vendor'] ?? null,
            'sku' => $firstVariant['sku'] ?? null,
            'barcode' => $firstVariant['barcode'] ?? null,
            'weight' => (float) ($firstVariant['weight'] ?? 0),
            'is_active' => ($node['status'] ?? 'active') === 'active',
            'shopify_product_id' => $shopifyId,
            'shopify_store_url' => $store->store_domain,
            'shopify_updated_at' => $shopifyUpdatedAt,
            'shopify_published_at' => ! empty($node['publishedAt']) ? new \DateTime($node['publishedAt']) : null,
            // ── Full variant mapping ────────────────────────────────
            'product_variants' => ! empty($localVariants) ? $localVariants : null,
        ];

        // Add variant mapping (first variant)
        $variantNode = $variantNodes[0]['node'] ?? $node['variants'][0] ?? [];
        $inventoryItemId = null;
        if (! empty($variantNode['inventoryItem']['id'])) {
            $parts = explode('/', $variantNode['inventoryItem']['id']);
            $inventoryItemId = end($parts);
        }
        $productData['shopify_variant_id'] = $this->extractId($variantNode['id'] ?? '');
        $productData['shopify_inventory_item_id'] = $inventoryItemId;
        $productData['shopify_store_id'] = $store->id;
        $productData['source'] = 'shopify';
        $productData['sync_status'] = 'synced';
        $productData['last_synced_at'] = now();

        // Store SEO and options in source_data
        $productData['shopify_source_data'] = [
            'shopify_id' => $shopifyId,
            'store_id' => $store->id,
            'handle' => $node['handle'] ?? null,
            'seo_title' => $seo['title'] ?? null,
            'seo_description' => $seo['description'] ?? null,
            'options' => $dto->options,
            'sync_updated_at' => now()->toIso8601String(),
        ];

        // ── Apply price sync policy ─────────────────────────────────
        if ($existingProduct) {
            if ($store->shouldSyncPrice($price, (float) $existingProduct->price)) {
                $productData['price'] = $price;
                $productData['compare_at_price'] = $compareAt;
            }
        } else {
            $productData['price'] = $price;
            $productData['compare_at_price'] = $compareAt;
        }

        // Sync tags
        $tags = array_map('trim', explode(',', $node['tags'] ?? ''));

        if ($existingProduct) {
            $existingProduct->update($productData);
            if (! empty($tags)) {
                $this->productManager->syncTags($existingProduct, $tags);
            }

            return ['created' => false, 'updated' => true, 'deactivated' => false];
        }

        $product = Product::create($productData);
        if (! empty($tags)) {
            $this->productManager->syncTags($product, $tags);
        }

        return ['created' => true, 'updated' => false, 'deactivated' => false];
    }

    /**
     * Sync inventory levels for a store from Shopify.
     * Uses GraphQL fetchInventoryLevels to batch-update stock quantities.
     */
    public function syncInventory(ShopifyStore $store): array
    {
        if (! $store->inventory_sync_enabled) {
            return ['store_id' => $store->id, 'store_domain' => $store->store_domain, 'updated' => 0, 'errors' => ['Inventory sync disabled for this store'], 'error_count' => 1];
        }

        $this->graphQL->forStore($store);

        $products = Product::where('source', 'shopify')
            ->where('shopify_store_id', $store->id)
            ->whereNotNull('shopify_inventory_item_id')
            ->get(['id', 'shopify_inventory_item_id', 'stock_quantity']);

        if ($products->isEmpty()) {
            return ['store_id' => $store->id, 'store_domain' => $store->store_domain, 'updated' => 0, 'errors' => [], 'error_count' => 0];
        }

        $updated = 0;
        $errors = [];

        // Process in batches of 50 (Shopify GraphQL limit)
        foreach ($products->chunk(50) as $chunk) {
            $inventoryItemIds = $chunk->pluck('shopify_inventory_item_id')->toArray();

            try {
                $result = $this->graphQL->fetchInventoryLevels($inventoryItemIds);
                if (! $result) {
                    $errors[] = 'Failed to fetch inventory levels from Shopify';

                    continue;
                }

                foreach ($result as $edge) {
                    $node = $edge['node'] ?? [];
                    $gid = $node['id'] ?? '';
                    $parts = explode('/', $gid);
                    $itemId = end($parts);

                    $inventoryLevels = $node['inventoryLevels']['edges'] ?? [];
                    $totalAvailable = 0;
                    foreach ($inventoryLevels as $level) {
                        $totalAvailable += (int) ($level['node']['available'] ?? 0);
                    }

                    $localProduct = $chunk->firstWhere('shopify_inventory_item_id', $itemId);
                    if ($localProduct && (int) $localProduct->stock_quantity !== $totalAvailable) {
                        Product::withoutTimestamps(fn () => Product::where('id', $localProduct->id)->update([
                            'stock_quantity' => $totalAvailable,
                            'sync_status' => 'synced',
                            'last_synced_at' => now(),
                        ])
                        );
                        $updated++;
                    }
                }
            } catch (\Exception $e) {
                $errors[] = 'Inventory batch error: '.$e->getMessage();
            }
        }

        return [
            'store_id' => $store->id,
            'store_domain' => $store->store_domain,
            'updated' => $updated,
            'errors' => $errors,
            'error_count' => count($errors),
        ];
    }

    /**
     * Sync inventory for all active stores.
     */
    public function syncAllInventory(): array
    {
        $stores = ShopifyStore::where('status', 'active')
            ->whereNull('deleted_at')
            ->where('inventory_sync_enabled', true)
            ->get();

        $results = [];
        foreach ($stores as $store) {
            $results[] = $this->syncInventory($store);
        }

        return $results;
    }

    /**
     * Extract numeric ID from Shopify's GID format.
     */
    protected function extractId(string $gid): string
    {
        $parts = explode('/', $gid);

        return end($parts) ?: $gid;
    }
}
