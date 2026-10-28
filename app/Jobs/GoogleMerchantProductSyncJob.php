<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\GoogleMerchantSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GoogleMerchantProductSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $productId,
    ) {
        $this->queue = 'high';
    }

    public function handle(GoogleMerchantSyncService $syncService): void
    {
        $product = Product::find($this->productId);

        if (! $product || ! $product->is_active) {
            return;
        }

        try {
            $syncService->syncProduct($product);
        } catch (\Throwable $e) {
            Log::error('Auto-sync product to Google Merchant failed', [
                'product_id' => $this->productId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
