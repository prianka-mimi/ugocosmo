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

class GoogleMerchantProductUnsyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public int $productId,
    ) {
        // Queue is set via the Queueable trait (a typed property redeclaration
        // would be composition-incompatible and fatal on class load).
        $this->onQueue('high');
    }

    public function handle(GoogleMerchantSyncService $syncService): void
    {
        $product = Product::find($this->productId);

        if (! $product) {
            return;
        }

        try {
            $syncService->deleteProduct($product);
        } catch (\Throwable $e) {
            Log::error('Auto-unsync product from Google Merchant failed', [
                'product_id' => $this->productId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
