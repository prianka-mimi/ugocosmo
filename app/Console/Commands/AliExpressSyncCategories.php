<?php

namespace App\Console\Commands;

use App\Jobs\AliExpressCategorySyncJob;
use App\Models\AliExpressAccount;
use Illuminate\Console\Command;

class AliExpressSyncCategories extends Command
{
    protected $signature = 'aliexpress:sync-categories';

    protected $description = 'Sync the AliExpress category tree for all connected accounts';

    public function handle(): int
    {
        $accounts = AliExpressAccount::active()->get();

        foreach ($accounts as $account) {
            AliExpressCategorySyncJob::dispatch($account->id);
        }

        $this->info("Dispatched category sync for {$accounts->count()} account(s).");

        return self::SUCCESS;
    }
}
