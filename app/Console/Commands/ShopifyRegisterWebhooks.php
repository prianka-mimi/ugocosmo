<?php

namespace App\Console\Commands;

use App\Manager\ShopifyWebhookManager;
use App\Models\ShopifyStore;
use Illuminate\Console\Command;

class ShopifyRegisterWebhooks extends Command
{
    protected $signature = 'shopify:register-webhooks
                            {store : Store ID or "all" for all active stores}';

    protected $description = 'Register webhooks for Shopify stores';

    public function handle(ShopifyWebhookManager $webhookManager): int
    {
        $storeInput = $this->argument('store');

        if ($storeInput === 'all') {
            $stores = ShopifyStore::where('status', 'active')
                ->whereNull('deleted_at')
                ->get();
        } else {
            $store = ShopifyStore::find((int) $storeInput);
            if (! $store) {
                $this->error("Store with ID {$storeInput} not found.");

                return Command::FAILURE;
            }
            $stores = collect([$store]);
        }

        $baseUrl = config('app.url');

        foreach ($stores as $store) {
            $this->info("Registering webhooks for {$store->store_domain}...");
            $results = $webhookManager->registerWebhooks($store, $baseUrl);

            $successCount = 0;
            foreach ($results as $result) {
                if ($result['success']) {
                    $successCount++;
                    $this->line("  ✅ {$result['topic']} registered");
                } else {
                    $this->warn("  ❌ {$result['topic']} failed: ".json_encode($result['errors']));
                }
            }

            $this->info("  {$successCount}/".count($results).' webhooks registered.');
        }

        return Command::SUCCESS;
    }
}
