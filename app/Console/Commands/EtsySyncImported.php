<?php

namespace App\Console\Commands;

use App\Jobs\EtsySyncImportedListingsJob;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Queues a refresh of imported Etsy listings (price/title/state) —
 * mirrors aliexpress:sync-stock. Heavy lifting happens in
 * EtsySyncImportedListingsJob (queue worker).
 */
class EtsySyncImported extends Command
{
    protected $signature = 'etsy:sync-imported
        {--product= : Sync only this imported product ID}';

    protected $description = 'Refresh imported Etsy listings (price/title/state) for all Etsy-sourced products';

    public function handle(): int
    {
        $productIds = Product::query()
            ->where('source', 'etsy')
            ->whereNotNull('etsy_listing_id')
            ->when($this->option('product'), fn ($q, $id) => $q->whereKey((int) $id))
            ->when(
                ! $this->option('product'),
                fn ($q) => $q->where(
                    fn ($q2) => $q2->whereNull('last_synced_at')
                        ->orWhere('last_synced_at', '<', now()->subMinutes((int) config('services.etsy.sync_minutes', 120)))
                )
            )
            ->pluck('id');

        if ($productIds->isEmpty()) {
            $this->info('No imported Etsy listings to sync.');

            return self::SUCCESS;
        }

        EtsySyncImportedListingsJob::dispatch($productIds->all());

        $this->info("Etsy sync dispatched for {$productIds->count()} product(s).");
        Log::info('EtsySyncImported: sync dispatched', ['product_count' => $productIds->count()]);

        return self::SUCCESS;
    }
}
