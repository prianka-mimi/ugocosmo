<?php

namespace App\Observers;

use App\Jobs\GoogleMerchantProductSyncJob;
use App\Jobs\GoogleMerchantProductUnsyncJob;
use App\Models\Product;

/**
 * Automatically syncs product changes to Google Merchant Center.
 *
 * Active products are synced on create/update. Inactive or deleted
 * products are removed from Merchant Center. All operations are
 * dispatched to the queue to never block the request.
 */
class ProductObserver
{
    /**
     * Handle the Product "created" event.
     */
    public function created(Product $product): void
    {
        if ($this->shouldSync($product)) {
            GoogleMerchantProductSyncJob::dispatch($product->id);
        }
    }

    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        $wasActive = $product->getOriginal('is_active');
        $isNowActive = $product->is_active;

        // Product became inactive — remove from Merchant Center
        if ($wasActive && ! $isNowActive) {
            GoogleMerchantProductUnsyncJob::dispatch($product->id);

            return;
        }

        // Product is active (or became active) — sync/update
        if ($this->shouldSync($product)) {
            GoogleMerchantProductSyncJob::dispatch($product->id);
        }
    }

    /**
     * Handle the Product "deleted" event.
     */
    public function deleted(Product $product): void
    {
        GoogleMerchantProductUnsyncJob::dispatch($product->id);
    }

    /**
     * Only sync products that have the minimum required data.
     */
    protected function shouldSync(Product $product): bool
    {
        if (! $product->is_active) {
            return false;
        }

        if (empty($product->title)) {
            return false;
        }

        if ((float) ($product->price ?? 0) <= 0) {
            return false;
        }

        // Must have at least one image
        if (empty($product->images) && empty($product->image_url)) {
            return false;
        }

        return true;
    }
}
