<?php

namespace App\Console\Commands;

use App\Jobs\AliExpressTokenRefreshJob;
use App\Models\AliExpressAccount;
use Illuminate\Console\Command;

class AliExpressRefreshTokens extends Command
{
    protected $signature = 'aliexpress:refresh-tokens {--all : Refresh tokens for every active account, not just expiring ones}';

    protected $description = 'Refresh AliExpress access tokens before they expire';

    public function handle(): int
    {
        $query = AliExpressAccount::active();

        if (! $this->option('all')) {
            $query->withExpiringToken(60);
        }

        $accounts = $query->get();

        foreach ($accounts as $account) {
            AliExpressTokenRefreshJob::dispatch($account->id);
        }

        $this->info("Dispatched token refresh for {$accounts->count()} account(s).");

        return self::SUCCESS;
    }
}
