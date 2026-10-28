<?php

namespace App\Console\Commands;

use App\Manager\ShopifySyncManager;
use App\Models\ShopifyStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ShopifySyncProducts extends Command
{
    protected $signature = 'shopify:sync-products
                            {--store= : Sync a specific store by ID}
                            {--timeout=300 : Timeout in seconds per store}
                            {--sync-inventory : Also sync inventory levels for all products}
                            {--inventory-only : Only sync inventory, skip product data sync}';

    protected $description = 'Sync products from all connected Shopify stores';

    public function handle(ShopifySyncManager $syncManager): int
    {
        $startTime = microtime(true);

        // ─── Inventory-only mode ──────────────────────────────────
        if ($this->option('inventory-only')) {
            $this->info('Starting Shopify inventory sync...');
            if ($storeId = $this->option('store')) {
                $store = ShopifyStore::find($storeId);
                if (! $store) {
                    $this->error("Store with ID {$storeId} not found.");

                    return Command::FAILURE;
                }
                $result = $syncManager->syncInventory($store);
                $this->outputInventoryResult($result);
            } else {
                $results = $syncManager->syncAllInventory();
                $this->outputInventoryResults($results);
            }
            $this->info('Inventory sync complete.');

            return Command::SUCCESS;
        }

        $this->info('Starting Shopify product sync...');

        if ($storeId = $this->option('store')) {
            $store = ShopifyStore::find($storeId);
            if (! $store) {
                $this->error("Store with ID {$storeId} not found.");

                return Command::FAILURE;
            }

            $this->info("Syncing store: {$store->store_domain} (ID: {$store->id})");
            $result = $syncManager->syncStore($store);
            $this->outputResult($result);
        } else {
            $results = $syncManager->syncAll();
            $totalCreated = 0;
            $totalUpdated = 0;
            $totalErrors = 0;

            foreach ($results as $result) {
                $this->outputResult($result);
                $totalCreated += $result['created'] ?? 0;
                $totalUpdated += $result['updated'] ?? 0;
                $totalErrors += $result['error_count'] ?? 0;
            }

            $this->newLine();
            $this->info('Sync complete for '.count($results).' stores.');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Products Created', $totalCreated],
                    ['Products Updated', $totalUpdated],
                    ['Errors', $totalErrors],
                    ['Duration', round(microtime(true) - $startTime, 2).'s'],
                ]
            );
        }

        // ─── Optional inventory sync ───────────────────────────────
        if ($this->option('sync-inventory')) {
            $this->newLine();
            $this->info('Starting inventory sync...');

            if ($storeId = $this->option('store')) {
                $invResult = $syncManager->syncInventory(
                    ShopifyStore::find((int) $storeId)
                );
                $this->outputInventoryResult($invResult);
            } else {
                $invResults = $syncManager->syncAllInventory();
                $this->outputInventoryResults($invResults);
            }
        }

        Log::info('Shopify sync command completed', [
            'duration' => round(microtime(true) - $startTime, 2),
        ]);

        return Command::SUCCESS;
    }

    protected function outputResult(array $result): void
    {
        $this->line("  Store: {$result['store_domain']}");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Created', $result['created'] ?? 0],
                ['Updated', $result['updated'] ?? 0],
                ['Errors', $result['error_count'] ?? 0],
            ]
        );

        if (! empty($result['errors'])) {
            $this->warn('  Errors:');
            foreach (array_slice($result['errors'], 0, 5) as $error) {
                $this->warn("    - {$error}");
            }
            if (count($result['errors']) > 5) {
                $this->warn('    ... and '.(count($result['errors']) - 5).' more');
            }
        }
    }

    protected function outputInventoryResult(array $result): void
    {
        $this->line("  Store: {$result['store_domain']}");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Inventory Updated', $result['updated'] ?? 0],
                ['Errors', $result['error_count'] ?? 0],
            ]
        );
        if (! empty($result['errors'])) {
            foreach (array_slice($result['errors'], 0, 5) as $e) {
                $this->warn("    - {$e}");
            }
        }
    }

    protected function outputInventoryResults(array $results): void
    {
        $totalUpdated = 0;
        foreach ($results as $r) {
            $this->outputInventoryResult($r);
            $totalUpdated += $r['updated'] ?? 0;
        }
        $this->newLine();
        $this->info('Inventory sync complete for '.count($results)." stores. {$totalUpdated} products updated.");
    }
}
