<?php

namespace App\Console\Commands;

use App\Jobs\AliExpressStockSyncJob;
use App\Models\AliExpressAccount;
use Illuminate\Console\Command;

/**
 * Queues a supplier stock sync for every active AliExpress account.
 * Heavy lifting happens in AliExpressStockSyncJob (queue worker).
 */
class AliExpressSyncStock extends Command
{
    protected $signature = 'aliexpress:sync-stock
        {--account= : Sync only this account ID}';

    protected $description = 'Sync supplier stock/cost for imported AliExpress listings (auto-disable out-of-stock, refresh prices)';

    public function handle(): int
    {
        $accounts = AliExpressAccount::query()
            ->where('status', AliExpressAccount::STATUS_ACTIVE)
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey((int) $id))
            ->pluck('id');

        if ($accounts->isEmpty()) {
            $this->info('No active AliExpress accounts to sync.');

            return self::SUCCESS;
        }

        foreach ($accounts as $accountId) {
            AliExpressStockSyncJob::dispatch($accountId);
            $this->line("Dispatched stock sync for account #{$accountId}.");
        }

        $this->info("Stock sync dispatched for {$accounts->count()} account(s).");

        return self::SUCCESS;
    }
}
