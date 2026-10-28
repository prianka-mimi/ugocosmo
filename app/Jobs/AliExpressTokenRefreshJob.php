<?php

namespace App\Jobs;

use App\Exceptions\AliExpress\OAuthException;
use App\Models\AliExpressAccount;
use App\Services\AliExpressTokenManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AliExpressTokenRefreshJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(protected int $accountId) {}

    public function handle(AliExpressTokenManager $tokens): void
    {
        $account = AliExpressAccount::find($this->accountId);
        if (! $account || ! $account->isActive()) {
            return;
        }

        // No-op when the token is still valid.
        if (! $account->isTokenExpired()) {
            return;
        }

        try {
            $tokens->refresh($account);
            Log::info('AliExpressTokenRefreshJob: token refreshed', ['account_id' => $this->accountId]);
        } catch (OAuthException $e) {
            // Refreshing failed (e.g. refresh token expired) — the seller
            // must reconnect. The account is marked via last_error.
            Log::warning('AliExpressTokenRefreshJob: refresh failed, seller must reconnect', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
