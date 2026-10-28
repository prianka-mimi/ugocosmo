<?php

namespace App\Console\Commands;

use App\Services\GoogleMerchantSyncService;
use Illuminate\Console\Command;

class GoogleMerchantSyncCommand extends Command
{
    protected $signature = 'google-merchant:sync
                            {--seller= : Sync products for a specific seller ID only}
                            {--unsync-inactive : Remove inactive products from Merchant Center}';

    protected $description = 'Sync active products to Google Merchant Center';

    public function handle(GoogleMerchantSyncService $syncService): int
    {
        if ($this->option('unsync-inactive')) {
            $deleted = $syncService->unsyncInactiveProducts();
            $this->info("Removed {$deleted} inactive products from Google Merchant Center.");

            return Command::SUCCESS;
        }

        $sellerId = $this->option('seller') ? (int) $this->option('seller') : null;

        $this->info($sellerId
            ? "Syncing products for seller #{$sellerId} to Google Merchant Center..."
            : 'Syncing all active products to Google Merchant Center...'
        );

        $result = $sellerId
            ? $syncService->syncSellerProducts($sellerId)
            : $syncService->syncAllActiveProducts();

        $this->newLine();
        $this->info('Sync completed:');
        $this->info("  Synced (new):  {$result['synced']}");
        $this->info("  Updated:       {$result['updated']}");
        $this->info("  Failed:        {$result['failed']}");

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
