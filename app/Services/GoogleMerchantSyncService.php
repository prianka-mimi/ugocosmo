<?php

namespace App\Services;

use App\DTOs\GoogleMerchant\GoogleMerchantProductDTO;
use App\Models\GoogleMerchantProduct;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates the sync of local Products to Google Merchant Center.
 *
 * Each product is tracked via GoogleMerchantProduct (sync status,
 * resource names, error state). Sync operations are idempotent —
 * re-syncing an already-synced product updates it in Merchant Center.
 */
class GoogleMerchantSyncService
{
    public function __construct(
        protected GoogleMerchantService $merchant,
    ) {}

    // ─── Single product sync ──────────────────────────────────────

    /**
     * Sync a single product to Google Merchant Center.
     * Creates or updates the ProductInput and records the sync state.
     *
     * @return GoogleMerchantProduct The sync tracking record
     */
    public function syncProduct(Product $product): GoogleMerchantProduct
    {
        $dto = GoogleMerchantProductDTO::fromProduct($product);
        $merchantId = config('services.google_merchant.merchant_id');
        $feedLabel = strtoupper((string) ($product->ship_from_address['country'] ?? 'US'));

        $sync = GoogleMerchantProduct::firstOrCreate(
            [
                'product_id' => $product->id,
                'content_language' => 'en',
                'feed_label' => $feedLabel,
            ],
            [
                'merchant_id' => $merchantId,
                'offer_id' => (string) $product->id,
                'sync_status' => GoogleMerchantProduct::STATUS_PENDING,
            ]
        );

        try {
            $response = $this->merchant->insertProduct($dto->toArray());

            $sync->update([
                'sync_status' => GoogleMerchantProduct::STATUS_SYNCED,
                'resource_name' => $response['name'] ?? null,
                'product_resource_name' => $response['product'] ?? null,
                'last_synced_at' => now(),
                'last_error' => null,
                'google_status' => null,
            ]);

            Log::info('Product synced to Google Merchant', [
                'product_id' => $product->id,
                'offer_id' => $sync->offer_id,
                'resource_name' => $sync->resource_name,
            ]);

            return $sync;
        } catch (Throwable $e) {
            $sync->update([
                'sync_status' => GoogleMerchantProduct::STATUS_ERROR,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'last_synced_at' => now(),
            ]);

            Log::error('Product sync to Google Merchant failed', [
                'product_id' => $product->id,
                'offer_id' => $sync->offer_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete a product from Google Merchant Center.
     */
    public function deleteProduct(Product $product): void
    {
        $feedLabel = strtoupper((string) ($product->ship_from_address['country'] ?? 'US'));

        $sync = GoogleMerchantProduct::where('product_id', $product->id)
            ->where('feed_label', $feedLabel)
            ->first();

        if (! $sync || $sync->sync_status === GoogleMerchantProduct::STATUS_DELETED) {
            return;
        }

        try {
            $this->merchant->deleteProduct($sync->offer_id, 'en', $feedLabel);

            $sync->update([
                'sync_status' => GoogleMerchantProduct::STATUS_DELETED,
                'last_synced_at' => now(),
            ]);

            Log::info('Product deleted from Google Merchant', [
                'product_id' => $product->id,
                'offer_id' => $sync->offer_id,
            ]);
        } catch (Throwable $e) {
            $sync->update([
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            Log::error('Product delete from Google Merchant failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // ─── Bulk sync ────────────────────────────────────────────────

    /**
     * Sync all active products from a seller to Google Merchant Center.
     * Returns summary counts.
     *
     * @return array{synced: int, updated: int, failed: int, skipped: int}
     */
    public function syncSellerProducts(int $sellerId): array
    {
        $products = Product::bySeller($sellerId)
            ->active()
            ->with([])
            ->get();

        $counts = ['synced' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($products as $product) {
            try {
                $existing = GoogleMerchantProduct::where('product_id', $product->id)->first();

                if ($existing && $existing->sync_status === GoogleMerchantProduct::STATUS_SYNCED) {
                    // Update existing
                    $this->syncProduct($product);
                    $counts['updated']++;
                } else {
                    // New sync
                    $this->syncProduct($product);
                    $counts['synced']++;
                }
            } catch (Throwable $e) {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * Sync all active products across the entire platform.
     * Designed to run via artisan command or queue job.
     *
     * @return array{synced: int, updated: int, failed: int, skipped: int}
     */
    public function syncAllActiveProducts(): array
    {
        $products = Product::active()
            ->where('is_active', true)
            ->with([])
            ->get();

        $counts = ['synced' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($products as $product) {
            try {
                $existing = GoogleMerchantProduct::where('product_id', $product->id)->first();

                if ($existing && $existing->sync_status === GoogleMerchantProduct::STATUS_SYNCED) {
                    $this->syncProduct($product);
                    $counts['updated']++;
                } else {
                    $this->syncProduct($product);
                    $counts['synced']++;
                }
            } catch (Throwable $e) {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * Unsync products that are now inactive (set availability to OUT_OF_STOCK
     * or delete from Merchant Center).
     */
    public function unsyncInactiveProducts(): int
    {
        $inactiveSyncs = GoogleMerchantProduct::synced()
            ->whereHas('product', fn ($q) => $q->where('is_active', false))
            ->get();

        $deleted = 0;

        foreach ($inactiveSyncs as $sync) {
            try {
                $this->merchant->deleteProduct(
                    $sync->offer_id,
                    $sync->content_language,
                    $sync->feed_label
                );

                $sync->update([
                    'sync_status' => GoogleMerchantProduct::STATUS_DELETED,
                    'last_synced_at' => now(),
                ]);

                $deleted++;
            } catch (Throwable $e) {
                Log::error('Failed to unsync inactive product', [
                    'google_merchant_product_id' => $sync->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $deleted;
    }
}
