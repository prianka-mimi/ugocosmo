<?php

namespace App\Jobs;

use App\DTOs\Shopify\ShopifyProductDTO;
use App\Manager\ImageManager;
use App\Manager\ProductManager;
use App\Manager\ShopifyImportAuditManager;
use App\Models\Product;
use App\Models\ShopifyImport;
use App\Models\ShopifyStore;
use App\Services\ShopifyGraphQLService;
use App\Services\ShopifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopifyProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes

    public int $tries = 3;

    public int $backoff = 30;

    public int $maxExceptions = 3;

    public function __construct(
        protected int $storeId,
        protected int $importId,
        protected int $userId,
        protected array $productIds = [],
        protected ?array $collectionIds = null,
        protected ?array $tags = null,
        protected ?string $statusFilter = null,
        protected ?string $updatedSince = null,
        protected bool $importAll = false,
        protected ?int $categoryId = null,
        protected string $defaultCondition = 'new',
        protected string $defaultCurrency = 'USD',
        protected bool $downloadImages = true,
        protected ?string $priceSyncPolicy = null,
        protected ?string $imageSyncStrategy = null,
        // ── Resume support ──────────────────────────────────────────
        protected bool $isResume = false,
        protected ?string $resumeAfterProductId = null,
    ) {
        $this->queue = 'shopify-imports';
    }

    public function handle(
        ShopifyGraphQLService $graphQL,
        ShopifyService $restService,
        ImageManager $imageManager,
        ProductManager $productManager,
    ): void {
        $store = ShopifyStore::find($this->storeId);
        if (! $store || $store->status !== 'active') {
            Log::error('ShopifyImportJob: Store not found or inactive', ['store_id' => $this->storeId]);
            $this->failImport('Store not found or inactive');

            return;
        }

        $import = ShopifyImport::find($this->importId);
        if (! $import) {
            Log::error('ShopifyImportJob: Import record not found', ['import_id' => $this->importId]);

            return;
        }

        $audit = app(ShopifyImportAuditManager::class);

        // ─── Release stale locks before acquiring ──────────────────────
        if ($import->isLocked()) {
            $staleReleased = $import->releaseStaleLock(30);
            if ($staleReleased) {
                $audit->logUnlocked($import);
                Log::info('ShopifyImportJob: Released stale lock on import', [
                    'import_id' => $this->importId,
                ]);
            }
        }

        // ─── Acquire import lock (prevents concurrent imports per store) ──
        $lockIdentifier = "job:{$this->job?->getJobId()}:import:{$this->importId}";
        if (! $import->acquireLock($lockIdentifier)) {
            Log::warning('ShopifyImportJob: Import is already locked (another process is handling it)', [
                'import_id' => $this->importId,
                'store_id' => $this->storeId,
            ]);
            $this->release(30); // Release back to queue with 30s delay

            return;
        }

        try {
            $audit->logLocked($import);

            // ─── Check cancellation before starting ─────────────────────
            $import->refresh();
            if ($import->status === 'cancelled') {
                $import->releaseLock();
                Log::info('ShopifyImportJob: Import was cancelled before processing', ['import_id' => $this->importId]);

                return;
            }

            $graphQL->forStore($store);
            $restService->forStore($store);

            // ─── Verify API connection / token validity ────────────────
            if (! $graphQL->verifyConnection()) {
                $errorMsg = 'Failed to connect to Shopify API. The access token for this store may be expired '
                    .'or invalid. Reconnect the store to refresh the token.';

                Log::error("ShopifyImportJob: API connection failed for store {$store->store_domain} - token may be expired");

                $import->update([
                    'status' => 'failed',
                    'error_message' => $errorMsg,
                    'completed_at' => now(),
                ]);
                $import->releaseLock();
                $audit->logLifecycle('failed', $import, null, [
                    'error' => 'API connection failed - token may be expired',
                    'store_domain' => $store->store_domain,
                ]);

                return;
            }

            $import->update([
                'status' => 'processing',
                'started_at' => $import->started_at ?? now(),
                'total_processed' => $import->total_processed ?? 0,
            ]);

            if (! $this->isResume) {
                $audit->logStarted($import);
            } else {
                $audit->logResumed($import);
            }

            $imported = $import->imported_count ?? 0;
            $updated = $import->updated_count ?? 0;
            $skipped = $import->skipped_count ?? 0;
            $failed = $import->failed_count ?? 0;
            $totalProcessed = $import->total_processed ?? 0;
            $errors = [];

            // ─── Resolve product IDs from filters ───────────────────────
            $resolvedProductIds = $this->resolveProductIds($graphQL, $store);
            $totalToProcess = count($resolvedProductIds);

            if ($totalToProcess === 0) {
                $import->update([
                    'status' => 'completed',
                    'total_products' => $import->total_products,
                    'completed_at' => now(),
                    'summary' => array_merge($import->summary ?? [], [
                        'message' => 'No products matched the specified filters.',
                    ]),
                ]);
                $import->releaseLock();
                $audit->logCompleted($import);
                Log::info("ShopifyImportJob: No products matched filters for store {$store->store_domain}");

                return;
            }

            if ($totalToProcess > ($import->total_products ?? 0)) {
                $import->update(['total_products' => $totalToProcess]);
            }

            // ─── Resume: skip already-processed products ────────────────
            if ($this->isResume && $this->resumeAfterProductId) {
                $resumeIndex = array_search($this->resumeAfterProductId, $resolvedProductIds, true);
                if ($resumeIndex !== false) {
                    $resolvedProductIds = array_slice($resolvedProductIds, $resumeIndex + 1);
                    Log::info('ShopifyImportJob: Resuming import, skipping '.($resumeIndex + 1).' already-processed products', [
                        'import_id' => $this->importId,
                        'remaining' => count($resolvedProductIds),
                    ]);
                }
            }

            // ─── Process products in chunks ────────────────────────────
            $chunks = array_chunk($resolvedProductIds, 50);

            foreach ($chunks as $chunkIndex => $chunk) {
                // ─── Check cancellation before each chunk ──────────────
                $import->refresh();
                if ($import->status === 'cancelled') {
                    Log::info('ShopifyImportJob: Import cancelled during processing', [
                        'import_id' => $this->importId,
                        'processed_so_far' => $totalProcessed,
                    ]);
                    break;
                }

                // ─── Process chunk within a database transaction ───────
                $chunkResult = DB::transaction(function () use (
                    $chunk, $store, $graphQL, $restService,
                    $imageManager, $productManager, $import, $audit,
                    &$imported, &$updated, &$skipped, &$failed, &$errors, &$totalProcessed
                ) {
                    $chunkImported = 0;
                    $chunkUpdated = 0;
                    $chunkSkipped = 0;
                    $chunkFailed = 0;
                    $chunkErrors = [];
                    $lastProcessedId = null;

                    foreach ($chunk as $shopifyProductId) {
                        try {
                            $result = $this->processSingleProduct(
                                $shopifyProductId,
                                $store,
                                $graphQL,
                                $restService,
                                $imageManager,
                                $productManager,
                                $import,
                                $audit,
                            );

                            $chunkImported += $result['imported'] ? 1 : 0;
                            $chunkUpdated += $result['updated'] ? 1 : 0;
                            $chunkSkipped += $result['skipped'] ? 1 : 0;
                            $chunkFailed += $result['failed'] ? 1 : 0;
                            $chunkErrors = array_merge($chunkErrors, $result['errors'] ?? []);

                            if (! empty($result['error'])) {
                                $chunkErrors[] = $result['error'];
                                $audit->appendError($import, $result['error']);
                            }

                            $lastProcessedId = $shopifyProductId;

                        } catch (\Exception $e) {
                            $chunkFailed++;
                            $errorMsg = "product_id:{$shopifyProductId}, error:{$e->getMessage()}";
                            $chunkErrors[] = $errorMsg;
                            $audit->logProductEvent($import, $store, $shopifyProductId, 'failed', null, $e->getMessage());
                            $audit->appendError($import, $errorMsg);
                            Log::error("ShopifyImportJob: Failed to import product {$shopifyProductId}: ".$e->getMessage());
                        }
                    }

                    // Update running totals within the transaction
                    $imported += $chunkImported;
                    $updated += $chunkUpdated;
                    $skipped += $chunkSkipped;
                    $failed += $chunkFailed;
                    $totalProcessed += ($chunkImported + $chunkUpdated + $chunkSkipped + $chunkFailed);

                    $import->update([
                        'imported_count' => $imported,
                        'updated_count' => $updated,
                        'skipped_count' => $skipped,
                        'failed_count' => $failed,
                        'total_processed' => $totalProcessed,
                        'last_processed_product_id' => $lastProcessedId,
                        'error_message' => ! empty($chunkErrors)
                            ? json_encode(array_slice($chunkErrors, 0, 100))
                            : $import->error_message,
                    ]);

                    return [
                        'imported' => $chunkImported,
                        'updated' => $chunkUpdated,
                        'skipped' => $chunkSkipped,
                        'failed' => $chunkFailed,
                        'errors' => $chunkErrors,
                    ];
                });

                // Merge chunk errors
                $errors = array_merge($errors, $chunkResult['errors']);
            }

            // ─── Determine final status ────────────────────────────────
            $import->refresh();

            if ($import->status === 'cancelled') {
                // Import was cancelled — leave as cancelled
            } else {
                $finalStatus = 'completed';
                if ($failed > 0 && $imported === 0 && $updated === 0) {
                    $finalStatus = 'failed';
                } elseif ($failed > 0) {
                    $finalStatus = 'partial';
                }

                $import->update([
                    'status' => $finalStatus,
                    'imported_count' => $imported,
                    'updated_count' => $updated,
                    'skipped_count' => $skipped,
                    'failed_count' => $failed,
                    'total_processed' => $totalProcessed,
                    'error_message' => ! empty($errors)
                        ? json_encode(array_slice($errors, 0, 100))
                        : null,
                    'completed_at' => now(),
                ]);

                // Update store's last sync timestamp
                $store->update(['last_sync_at' => now()]);

                $audit->logCompleted($import->fresh());
            }

            // Release the lock
            $import->releaseLock();
            $audit->logUnlocked($import);

            Log::info("ShopifyImportJob completed for store {$store->store_domain}", [
                'import_id' => $this->importId,
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
                'total' => $import->total_products,
                'status' => $import->fresh()->status,
            ]);

        } catch (\Exception $e) {
            // Ensure lock is released on failure
            $import->refresh();
            $import->releaseLock();

            $import->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $audit->logLifecycle('failed', $import, null, ['error' => $e->getMessage()]);
            $audit->logUnlocked($import);

            Log::error("ShopifyImportJob: Fatal error for import {$this->importId}: ".$e->getMessage());

            throw $e; // Re-throw so Laravel queue tracks the failure
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $import = ShopifyImport::find($this->importId);
        if ($import) {
            $import->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
            $import->releaseLock();
            Log::error("ShopifyImportJob [{$this->importId}] permanently failed: ".$exception->getMessage());
        }
    }

    /**
     * Resolve which product IDs to import based on filters provided.
     */
    protected function resolveProductIds(
        ShopifyGraphQLService $graphQL,
        ShopifyStore $store,
    ): array {
        // Explicit product IDs take priority
        if (! empty($this->productIds)) {
            return $this->productIds;
        }

        $allIds = [];
        $seenIds = [];

        // Import all products from the store
        if ($this->importAll) {
            $cursor = null;
            $hasMore = true;
            while ($hasMore) {
                $result = $graphQL->fetchProducts(250, $cursor);
                if (! $result) {
                    break;
                }

                foreach ($result['products'] as $node) {
                    $id = self::extractId($node['id']);
                    if (! isset($seenIds[$id])) {
                        $allIds[] = $id;
                        $seenIds[$id] = true;
                    }
                }

                $cursor = $result['end_cursor'];
                $hasMore = $result['has_next_page'];
            }

            return $allIds;
        }

        // Collection-based import
        if (! empty($this->collectionIds)) {
            foreach ($this->collectionIds as $collectionId) {
                $cursor = null;
                $hasMore = true;
                while ($hasMore) {
                    $result = $graphQL->fetchProductsByCollection($collectionId, 250, $cursor);
                    if (! $result) {
                        break;
                    }

                    foreach ($result['products'] as $node) {
                        $id = self::extractId($node['id']);
                        if (! isset($seenIds[$id])) {
                            $allIds[] = $id;
                            $seenIds[$id] = true;
                        }
                    }

                    $cursor = $result['end_cursor'];
                    $hasMore = $result['has_next_page'];
                }
            }
        }

        // Tag-based filtering
        if (! empty($this->tags)) {
            foreach ($this->tags as $tag) {
                $cursor = null;
                $hasMore = true;
                while ($hasMore) {
                    $result = $graphQL->fetchProductsByTag($tag, 250, $cursor);
                    if (! $result) {
                        break;
                    }

                    foreach ($result['products'] as $node) {
                        $id = self::extractId($node['id']);
                        if (! isset($seenIds[$id])) {
                            $allIds[] = $id;
                            $seenIds[$id] = true;
                        }
                    }

                    $cursor = $result['end_cursor'];
                    $hasMore = $result['has_next_page'];
                }
            }
        }

        // Incremental sync filter (only products updated since)
        if (! empty($this->updatedSince)) {
            $cursor = null;
            $hasMore = true;
            while ($hasMore) {
                $result = $graphQL->fetchProductsUpdatedSince($this->updatedSince, 250, $cursor);
                if (! $result) {
                    break;
                }

                foreach ($result['products'] as $node) {
                    $id = self::extractId($node['id']);
                    if (! isset($seenIds[$id])) {
                        $allIds[] = $id;
                        $seenIds[$id] = true;
                    }
                }

                $cursor = $result['end_cursor'];
                $hasMore = $result['has_next_page'];
            }
        }

        return array_values(array_unique($allIds));
    }

    /**
     * Process a single product: fetch from Shopify, deduplicate, save or update locally.
     */
    protected function processSingleProduct(
        string $shopifyProductId,
        ShopifyStore $store,
        ShopifyGraphQLService $graphQL,
        ShopifyService $restService,
        ImageManager $imageManager,
        ProductManager $productManager,
        ShopifyImport $import,
        ShopifyImportAuditManager $audit,
    ): array {
        $result = [
            'imported' => false,
            'updated' => false,
            'skipped' => false,
            'failed' => false,
            'error' => null,
            'errors' => [],
            'product' => null,
        ];

        // ─── Composite dedup: (source + shopify_product_id + shopify_store_id) ──
        $existing = Product::where('source', 'shopify')
            ->where('shopify_product_id', $shopifyProductId)
            ->where('shopify_store_id', $store->id)
            ->first();

        // Fetch product from Shopify
        // ─── Fetch product from Shopify API ────────────────────────
        $productNode = $graphQL->fetchProduct($shopifyProductId);
        if (! $productNode) {
            $errorDetail = 'Failed to fetch product from Shopify. The product may not exist, '
                .'or the API token for this store may be expired or invalid.';
            $audit->logProductEvent($import, $store, $shopifyProductId, 'failed', null, $errorDetail);
            $result['failed'] = true;
            $result['error'] = "product_id:{$shopifyProductId}, error:{$errorDetail}";
            $result['errors'][] = $result['error'];

            return $result;
        }

        $dto = ShopifyProductDTO::fromGraphQL($productNode);

        // Apply status filter (skip if doesn't match)
        if (! empty($this->statusFilter) && $this->statusFilter !== 'any') {
            $statusMap = [
                'active' => 'active',
                'draft' => 'draft',
                'archived' => 'archived',
            ];
            $expectedStatus = $statusMap[$this->statusFilter] ?? null;
            if ($expectedStatus && $dto->status !== $expectedStatus) {
                $audit->logProductEvent($import, $store, $shopifyProductId, 'skipped',
                    "Status '{$dto->status}' does not match filter '{$this->statusFilter}'");
                $result['skipped'] = true;

                return $result;
            }
        }

        if ($existing) {
            // ─── UPDATE existing product ───────────────────────────────
            $this->updateLocalProduct($existing, $dto, $store, $productManager);
            $result['updated'] = true;
            $result['product'] = $existing;

            $audit->logProductEvent($import, $store, $shopifyProductId, 'updated',
                'Updated via import (existing dedup match: source+product_id+store_id)');

        } else {
            // ─── CREATE new product ────────────────────────────────────
            $firstVariant = $dto->variants[0] ?? null;
            $localVariants = $dto->buildLocalVariants();

            $productData = [
                'seller_id' => $store->profile_id,
                'title' => $dto->title,
                'description' => $dto->description,
                'price' => $dto->price,
                'currency' => $this->defaultCurrency,
                'compare_at_price' => $dto->compareAtPrice,
                'category_id' => $this->categoryId,
                'stock_quantity' => $dto->inventoryQuantity,
                'product_type' => $dto->productType,
                'condition' => $this->defaultCondition,
                'brand' => $dto->brand,
                'sku' => $dto->sku,
                'barcode' => $dto->barcode ?? null,
                'weight' => $dto->weight,
                'is_active' => $dto->status === 'active',
                'shopify_product_id' => $dto->shopifyId,
                'shopify_store_url' => $store->store_domain,
                'shopify_store_id' => $store->id,
                'shopify_updated_at' => $dto->shopifyUpdatedAt ? new \DateTime($dto->shopifyUpdatedAt) : null,
                'shopify_published_at' => ! empty($productNode['publishedAt'])
                    ? new \DateTime($productNode['publishedAt'])
                    : null,
                'source' => 'shopify',
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                // ── Full variant mapping (all variants, not just first) ──
                'product_variants' => ! empty($localVariants) ? $localVariants : null,
                // ── First variant as primary ────────────────────────────
                'shopify_variant_id' => $firstVariant?->id ?? null,
                'shopify_inventory_item_id' => $firstVariant?->inventoryItemId ?? null,
                // ── SEO & Metadata ─────────────────────────────────────
                'shopify_source_data' => [
                    'shopify_id' => $dto->shopifyId,
                    'store_id' => $store->id,
                    'handle' => $dto->handle,
                    'seo_title' => $dto->seoTitle,
                    'seo_description' => $dto->seoDescription,
                    'options' => $dto->options,
                    'all_variants' => array_map(fn ($v) => $v->toArray(), $dto->variants),
                    'imported_at' => now()->toIso8601String(),
                ],
            ];

            $product = Product::create($productData);

            // Sync tags
            if (! empty($dto->tags)) {
                $productManager->syncTags($product, $dto->tags);
            }

            // Download images (respects policy)
            if ($this->shouldDownloadImages($store, $product)) {
                $this->downloadProductImages($restService, $imageManager, $product, $dto);
            }

            $result['imported'] = true;
            $result['product'] = $product;

            $audit->logProductEvent($import, $store, $shopifyProductId, 'imported');
        }

        return $result;
    }

    protected function updateLocalProduct(Product $product, ShopifyProductDTO $dto, ShopifyStore $store, ProductManager $productManager): void
    {
        $localVariants = $dto->buildLocalVariants();
        $pricePolicy = $store->resolvePricePolicy($this->priceSyncPolicy);

        $updateData = [
            'title' => $dto->title,
            'stock_quantity' => $dto->inventoryQuantity,
            'product_type' => $dto->productType,
            'brand' => $dto->brand,
            'sku' => $dto->sku,
            'barcode' => $dto->barcode ?? $product->barcode,
            'weight' => $dto->weight,
            'is_active' => $dto->status === 'active',
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            'shopify_updated_at' => $dto->shopifyUpdatedAt ? new \DateTime($dto->shopifyUpdatedAt) : null,
            // ── Full variant mapping ──────────────────────────────────
            'product_variants' => ! empty($localVariants) ? $localVariants : $product->product_variants,
        ];

        // ── Apply price sync policy ─────────────────────────────────
        if ($store->shouldSyncPrice($dto->price, (float) $product->price)) {
            $updateData['price'] = $dto->price;
            $updateData['compare_at_price'] = $dto->compareAtPrice;
        }

        // Update variant mapping
        $firstVariant = $dto->variants[0] ?? null;
        if ($firstVariant) {
            $updateData['shopify_variant_id'] = $firstVariant->id;
            $updateData['shopify_inventory_item_id'] = $firstVariant->inventoryItemId;
        }

        $product->update($updateData);

        if (! empty($dto->tags)) {
            $productManager->syncTags($product, $dto->tags);
        }

        // ── Apply image sync strategy ──────────────────────────────
        if ($this->shouldDownloadImages($store, $product) && ! empty($dto->imageUrl)) {
            $this->downloadProductImages($restService, $imageManager, $product, $dto);
        }
    }

    protected function downloadProductImages(ShopifyService $restService, ImageManager $imageManager, Product $product, ShopifyProductDTO $dto): void
    {
        try {
            $uploadedPath = $this->downloadSingleImage($restService, $imageManager, $dto->imageUrl);
            if ($uploadedPath) {
                $product->image_url = $uploadedPath;
            }

            $additionalPaths = [];
            foreach (array_slice($dto->images, 0, 10) as $imgUrl) {
                if ($imgUrl !== $dto->imageUrl) {
                    $path = $this->downloadSingleImage($restService, $imageManager, $imgUrl);
                    if ($path) {
                        $additionalPaths[] = $path;
                    }
                }
            }

            if (! empty($additionalPaths)) {
                $product->images = $additionalPaths;
            }

            $product->save();
        } catch (\Exception $e) {
            Log::error("ShopifyImportJob: Image download failed for product {$dto->shopifyId}: ".$e->getMessage());
        }
    }

    protected function downloadSingleImage(ShopifyService $restService, ImageManager $imageManager, string $url): ?string
    {
        $imageContent = $restService->downloadImage($url);
        if (! $imageContent) {
            return null;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'shopify_');
        file_put_contents($tempPath, $imageContent);

        $uploadedPath = $imageManager->upload(
            new UploadedFile($tempPath, basename($url)),
            'products'
        );

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        return $uploadedPath;
    }

    protected function failImport(string $reason): void
    {
        $import = ShopifyImport::find($this->importId);
        if ($import) {
            $import->update([
                'status' => 'failed',
                'error_message' => $reason,
                'completed_at' => now(),
            ]);
            $import->releaseLock();
        }
    }

    /**
     * Determine if images should be downloaded based on strategy.
     * Respects both the legacy download_images flag and new image_sync_strategy.
     */
    protected function shouldDownloadImages(ShopifyStore $store, Product $product): bool
    {
        // Legacy flag takes precedence: if user explicitly said 'false', respect it
        if (! $this->downloadImages) {
            return false;
        }

        $strategy = $store->resolveImageStrategy($this->imageSyncStrategy);

        return match ($strategy) {
            'always' => true,
            'new_only' => empty($product->image_url) && empty($product->images),
            'skip' => false,
            default => true,
        };
    }

    /**
     * Extract numeric ID from Shopify's GID format.
     */
    protected static function extractId(string $gid): string
    {
        $parts = explode('/', $gid);

        return end($parts) ?: $gid;
    }
}
