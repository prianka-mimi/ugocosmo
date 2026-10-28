<?php

namespace App\Console\Commands;

use App\Jobs\EtsyTokenRefreshJob;
use App\Models\EtsyAccount;
use Illuminate\Console\Command;

class EtsyRefreshTokens extends Command
{
    protected $signature = 'etsy:refresh-tokens {--all : Refresh tokens for every active account, not just expiring ones}';

    protected $description = 'Refresh Etsy access tokens before they expire (1h tokens, 90d refresh window)';

    public function handle(): int
    {
        $query = EtsyAccount::active();

        if (! $this->option('all')) {
            $query->withExpiringToken(60);
        }

        $accounts = $query->get();

        foreach ($accounts as $account) {
            EtsyTokenRefreshJob::dispatch($account->id);
        }

        $this->info("Dispatched token refresh for {$accounts->count()} account(s).");

        return self::SUCCESS;
    }
}
