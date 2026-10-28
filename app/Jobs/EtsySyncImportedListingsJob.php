<?php

namespace App\Jobs;

use App\DTOs\Etsy\EtsyImportOptions;
use App\Models\Product;
use App\Services\EtsyImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes imported Etsy listings (price/title/state) via
 * EtsyImportService::refreshImported, in chunks to bound memory.
 *
 * Inventory is never touched: stock_quantity/reserved_quantity are owned
 * by the local inventory workflow, and seller-edited prices are skipped
 * by the service (seller_priced contract).
 */
class EtsySyncImportedListingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(
        protected array $productIds,
    ) {
        $this->queue = 'default';
    }

    public function handle(EtsyImportService $importService): void
    {
        $options = new EtsyImportOptions(categoryId: null, isActive: true);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach (array_chunk($this->productIds, 50) as $chunk) {
            $products = Product::query()
                ->where('source', 'etsy')
                ->whereIn('id', $chunk)
                ->get();

            foreach ($products as $product) {
                $result = $importService->refreshImported($product, $options);

                $updated += $result['updated'] ? 1 : 0;
                $skipped += $result['skipped'] ? 1 : 0;
                $failed += $result['failed'] ? 1 : 0;
            }

            $imported += $products->count();
        }

        Log::info('EtsySyncImportedListingsJob: sync completed', [
            'requested' => count($this->productIds),
            'processed' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }
}
