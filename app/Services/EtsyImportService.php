<?php

namespace App\Services;

use App\DTOs\Etsy\EtsyImportOptions;
use App\DTOs\Etsy\EtsyListingDTO;
use App\Exceptions\Etsy\EtsyApiException;
use App\Models\EtsyAccount;
use App\Models\EtsyImport;
use App\Models\EtsyImportLog;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Converts explicitly selected Etsy listings into marketplace listings.
 *
 * Behavioral contract (mirrors AliExpressImportService):
 *  - Imported products are NOT inventory: stock stays untouched (Etsy
 *    quantity is preview data; only the local inventory workflow writes
 *    stock_quantity).
 *  - Idempotent: re-importing an already imported listing updates it,
 *    unless nothing changed (skip).
 *  - Concurrency-safe: the (seller_id, etsy_listing_id) unique index backs
 *    the create path; a lost race is treated as an idempotent re-import.
 */
class EtsyImportService
{
    public function __construct(
        protected EtsyListingService $listings,
    ) {}

    /**
     * Import (create or update) a single selected listing.
     *
     * @return array{imported: bool, updated: bool, skipped: bool, failed: bool, error: string|null, product: Product|null}
     */
    public function importListing(int $profileId, int $listingId, EtsyImportOptions $options, ?EtsyAccount $account = null): array
    {
        $result = [
            'imported' => false,
            'updated' => false,
            'skipped' => false,
            'failed' => false,
            'error' => null,
            'product' => null,
        ];

        try {
            $dto = $this->listings->getListing($listingId, $account);

            $product = $this->findImported($profileId, $listingId);

            if ($product && $this->isUnchanged($product, $dto)) {
                $this->log(null, $profileId, $dto, EtsyImportLog::ACTION_SKIPPED, 'Unchanged since last import');
                $result['skipped'] = true;
                $result['product'] = $product;

                return $result;
            }

            $attributes = $this->buildAttributes($profileId, $dto, $options);
            $attributes['etsy_account_id'] = $account?->id;

            if ($product) {
                $product->update($attributes);
                $this->log(null, $profileId, $dto, EtsyImportLog::ACTION_UPDATED, 'Updated via import (existing listing)');
                $result['updated'] = true;
            } else {
                try {
                    $product = Product::create($attributes);
                } catch (UniqueConstraintViolationException $e) {
                    // Concurrent import of the same listing by this seller:
                    // the unique index (seller_id, etsy_listing_id) rejected
                    // the insert. Treat it as an idempotent re-import.
                    $product = $this->findImported($profileId, $listingId);

                    if (! $product) {
                        throw $e;
                    }

                    $attributes = $this->buildAttributes($profileId, $dto, $options);
                    $attributes['etsy_account_id'] = $account?->id;
                    $product->update($attributes);
                }

                $this->log(null, $profileId, $dto, EtsyImportLog::ACTION_IMPORTED);
                $result['imported'] = true;
            }

            $result['product'] = $product;

            return $result;
        } catch (EtsyApiException $e) {
            Log::error("EtsyImportService: Etsy API failure for listing {$listingId}: ".$e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $result['failed'] = true;
            $result['error'] = $e->getMessage();

            $this->log(null, $profileId, null, EtsyImportLog::ACTION_FAILED, null, (string) $listingId, $e->getMessage());

            Log::error("EtsyImportService: failed to import listing {$listingId}: ".$e->getMessage());

            return $result;
        }
    }

    /**
     * Refresh an already imported listing (scheduled sync path).
     *
     * @return array{imported: bool, updated: bool, skipped: bool, failed: bool, error: string|null, product: Product|null}
     */
    public function refreshImported(Product $product, EtsyImportOptions $options): array
    {
        $listingId = (int) $product->etsy_listing_id;

        $result = [
            'imported' => false,
            'updated' => false,
            'skipped' => false,
            'failed' => false,
            'error' => null,
            'product' => $product,
        ];

        try {
            $dto = $this->listings->getListing($listingId, $product->etsyAccount);

            // A seller-edited price is seller-owned: never let the sync
            // overwrite it (mirrors the AliExpress stock-sync contract).
            if ($this->isSellerPriced($product, $dto)) {
                $this->log(null, (int) $product->seller_id, $dto, EtsyImportLog::ACTION_SKIPPED, 'Seller-managed price — sync skipped');
                $result['skipped'] = true;

                return $result;
            }

            if ($this->isUnchanged($product, $dto)) {
                $product->update(['last_synced_at' => now()]);
                $this->log(null, (int) $product->seller_id, $dto, EtsyImportLog::ACTION_SKIPPED, 'Unchanged since last sync');
                $result['skipped'] = true;

                return $result;
            }

            $product->update($this->buildAttributes((int) $product->seller_id, $dto, $options, true));
            $this->log(null, (int) $product->seller_id, $dto, EtsyImportLog::ACTION_UPDATED, 'Updated via scheduled sync');
            $result['updated'] = true;

            return $result;
        } catch (\Throwable $e) {
            $result['failed'] = true;
            $result['error'] = $e->getMessage();

            $this->log(null, (int) $product->seller_id, null, EtsyImportLog::ACTION_FAILED, null, (string) $listingId, $e->getMessage());

            Log::error("EtsyImportService: failed to refresh listing {$listingId}: ".$e->getMessage());

            return $result;
        }
    }

    // ─── Protected helpers ─────────────────────────────────────────

    /**
     * The imported listing for this seller, if any.
     */
    protected function findImported(int $profileId, int $listingId): ?Product
    {
        return Product::query()
            ->where('source', 'etsy')
            ->where('seller_id', $profileId)
            ->where('etsy_listing_id', (string) $listingId)
            ->first();
    }

    /**
     * True when the seller manually changed the listing after import
     * (title/price edits set the explicit `seller_priced` flag in
     * etsy_source_data — the same contract as AliExpress imports).
     * A pure price divergence is NOT enough: Etsy-side price changes must
     * still sync down to products the seller hasn't touched.
     */
    protected function isSellerPriced(Product $product, EtsyListingDTO $dto): bool
    {
        $source = $product->etsy_source_data ?? [];

        return ($source['seller_priced'] ?? false) === true;
    }

    protected function isUnchanged(Product $product, EtsyListingDTO $dto): bool
    {
        if ((string) $product->title !== $dto->title) {
            return false;
        }

        if ($dto->price !== null && (float) $product->price !== (float) $dto->price) {
            return false;
        }

        if ((string) ($product->etsy_state ?? '') !== $dto->state) {
            return false;
        }

        $updatedAt = $product->etsy_updated_at;

        if ($dto->updatedAt !== null && $updatedAt !== null
            && $dto->updatedAt->getTimestamp() !== $updatedAt->getTimestamp()) {
            return false;
        }

        return true;
    }

    /**
     * Attributes for creating OR refreshing an imported listing.
     *
     * `$isExisting` must be true when updating a listing the seller already
     * imported: Etsy data is supplier preview data and must NEVER overwrite
     * local inventory — received stock (stock_quantity) and open
     * reservations (reserved_quantity) are owned exclusively by
     * InventoryService.
     */
    protected function buildAttributes(int $profileId, EtsyListingDTO $dto, EtsyImportOptions $options, bool $isExisting = false): array
    {
        // Etsy price is the marketplace price (no supplier cost → no margin).
        $price = $dto->price;

        $attributes = [
            'seller_id' => $profileId,
            'title' => $dto->title,
            'description' => $dto->description,
            'price' => $price,
            'currency' => $dto->currency ?? config('services.etsy.currency', 'USD'),
            'category_id' => $options->categoryId,
            'product_type' => 'physical',
            'condition' => ($dto->whoMade ?? '') === 'handmade' ? 'handmade' : 'new',
            'sku' => null,
            'is_active' => $options->isActive && $dto->state === 'active',
            'image_url' => $dto->images[0]['url'] ?? null,
            'images' => array_map(fn (array $image) => $image['url'], $dto->images) ?: null,
            'source' => 'etsy',
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            'etsy_listing_id' => (string) $dto->listingId,
            'etsy_shop_id' => $dto->shopId,
            'etsy_shop_name' => $dto->shopName,
            'etsy_state' => $dto->state,
            'etsy_product_url' => $dto->url,
            'etsy_updated_at' => $dto->updatedAt,
            'etsy_source_data' => [
                'listing_id' => $dto->listingId,
                'user_id' => $dto->userId,
                'shop_id' => $dto->shopId,
                'shop_name' => $dto->shopName,
                'title' => $dto->title,
                'state' => $dto->state,
                'quantity' => $dto->quantity,
                'price' => $dto->price,
                'currency' => $dto->currency,
                'url' => $dto->url,
                'tags' => $dto->tags,
                'materials' => $dto->materials,
                'taxonomy_path' => $dto->taxonomyPath,
                'is_personalizable' => $dto->isPersonalizable,
                'personalization_is_required' => $dto->personalizationIsRequired,
                'personalization_char_count_max' => $dto->personalizationCharCountMax,
                'personalization_instructions' => $dto->personalizationInstructions,
                'has_variations' => $dto->hasVariations,
                'who_made' => $dto->whoMade,
                'when_made' => $dto->whenMade,
                'processing_min' => $dto->processingMin,
                'processing_max' => $dto->processingMax,
                'images' => $dto->images,
                'imported_at' => now()->toIso8601String(),
            ],
        ];

        if (! $isExisting) {
            $attributes['stock_quantity'] = 0;
            $attributes['reserved_quantity'] = 0;
        }

        return $attributes;
    }

    protected function log(
        ?EtsyImport $import,
        int $profileId,
        ?EtsyListingDTO $dto,
        string $action,
        ?string $reason = null,
        ?string $listingIdOverride = null,
        ?string $errorMessage = null,
    ): void {
        // No import run context (e.g. scheduled sync) — no log row.
        if ($import === null) {
            return;
        }

        EtsyImportLog::create([
            'etsy_import_id' => $import->id,
            'profile_id' => $profileId,
            'etsy_listing_id' => $listingIdOverride ?? (string) ($dto?->listingId ?? ''),
            'product_title' => $dto?->title,
            'action' => $action,
            'reason' => $reason,
            'error_message' => $errorMessage,
        ]);
    }
}
