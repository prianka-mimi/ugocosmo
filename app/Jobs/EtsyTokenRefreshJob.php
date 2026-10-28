<?php

namespace App\Jobs;

use App\Exceptions\Etsy\EtsyOAuthException;
use App\Models\EtsyAccount;
use App\Services\EtsyTokenManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors AliExpressTokenRefreshJob: refreshes one Etsy account's access
 * token via the 90-day refresh token. Dispatched hourly by
 * `etsy:refresh-tokens` for accounts whose token expires within the hour.
 */
class EtsyTokenRefreshJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(protected int $accountId) {}

    public function handle(EtsyTokenManager $tokens): void
    {
        $account = EtsyAccount::find($this->accountId);
        if (! $account || ! $account->isActive()) {
            return;
        }

        // No-op when the token is still valid.
        if (! $account->isTokenExpired()) {
            return;
        }

        try {
            $tokens->refresh($account);
            Log::info('EtsyTokenRefreshJob: token refreshed', ['account_id' => $this->accountId]);
        } catch (EtsyOAuthException $e) {
            // Refresh failed (e.g. 90-day refresh window elapsed) — the
            // seller must reconnect. The account is marked via last_error.
            Log::warning('EtsyTokenRefreshJob: refresh failed, seller must reconnect', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
