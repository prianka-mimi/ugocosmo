<?php

namespace App\Services;

use App\Exceptions\AliExpress\OAuthException;
use App\Models\AliExpressAccount;
use App\Models\AliExpressSyncLog;
use App\Repositories\AliExpressAccountRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Centralized AliExpress token management.
 *
 * Guarantees a valid access token for API calls:
 *  - returns the decrypted token when still valid
 *  - automatically refreshes via the refresh token when expired
 *  - uses a distributed lock so concurrent jobs never double-refresh
 *  - marks the account as needing reconnection only when refresh fails
 */
class AliExpressTokenManager
{
    public const LOCK_SECONDS = 30;

    public function __construct(
        protected AliExpressAccountRepository $accounts,
        protected AliExpressOAuthService $oauth,
        protected AliExpressSyncLogger $syncLogger,
    ) {}

    /**
     * Resolve a valid (non-expired, decrypted) access token.
     *
     * @throws OAuthException when the token is expired and cannot be refreshed
     */
    public function ensureValidToken(AliExpressAccount $account): string
    {
        if (! $account->isActive()) {
            throw new \Exception('AliExpress account is disabled. Reconnect it first.', 403);
        }

        $token = $this->accounts->decryptAccessToken($account);

        if (! $account->isTokenExpired()) {
            return $token;
        }

        return $this->refresh($account);
    }

    /**
     * Refresh the account's tokens now.
     *
     * @throws OAuthException
     */
    public function refresh(AliExpressAccount $account): string
    {
        $lock = Cache::lock("aliexpress_token_refresh_{$account->id}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            // Another process is refreshing — wait briefly and re-read.
            sleep(2);
            $fresh = $this->accounts->findByIdRaw($account->id);
            if ($fresh && ! $fresh->isTokenExpired()) {
                return $this->accounts->decryptAccessToken($fresh);
            }

            throw new OAuthException('AliExpress token refresh already in progress. Try again shortly.', 429);
        }

        // Double-check after acquiring the lock: a concurrent refresh may
        // have completed between our expiry check and lock acquisition.
        // Refreshing again would burn the rotated refresh token returned
        // to the first refresher (AliExpress rotates tokens on every
        // /auth/token/refresh) and desync our stored credentials.
        $account->refresh();
        if (! $account->isTokenExpired()) {
            $lock->release();

            return $this->accounts->decryptAccessToken($account);
        }

        $syncLog = $this->syncLogger->start(
            $account->id,
            AliExpressSyncLog::TYPE_TOKEN_REFRESH
        );

        try {
            $refreshToken = $this->accounts->decryptRefreshToken($account);

            if (! $refreshToken || $account->isRefreshTokenExpired()) {
                throw new OAuthException(
                    'Your AliExpress connection has expired. Please reconnect your account.',
                    401
                );
            }

            $data = $this->oauth->refreshToken($refreshToken, $account->region, $account->id);

            // Official docs (Seller authorization introduction): refreshing
            // returns a NEW access_token AND refresh_token, and "the duration
            // of the refresh token will not be reset". The refresh_expires_in
            // of a refresh response is therefore treated as remaining time —
            // never extend beyond the validity already stored.
            $newRefreshValidUntil = $data->refreshTokenValidUntil();
            if ($account->refresh_token_valid_until !== null
                && ($newRefreshValidUntil === null || $newRefreshValidUntil > $account->refresh_token_valid_until)
            ) {
                $newRefreshValidUntil = $account->refresh_token_valid_until;
            }

            $this->accounts->update($account, [
                'access_token' => $data->accessToken,
                'refresh_token' => $data->refreshToken ?? $refreshToken,
                'token_expires_at' => $data->tokenExpiresAt(),
                'refresh_token_valid_until' => $newRefreshValidUntil,
                'last_error' => null,
            ]);

            $this->syncLogger->complete($syncLog, 'Access token refreshed successfully.');

            Log::info('AliExpress token refreshed automatically', [
                'account_id' => $account->id,
                'profile_id' => $account->profile_id,
            ]);

            return $data->accessToken;
        } catch (OAuthException $e) {
            $this->accounts->update($account, [
                'last_error' => 'Token refresh failed: '.$e->getMessage(),
            ]);

            $this->syncLogger->fail($syncLog, 'Token refresh failed: '.$e->getMessage());

            Log::error('AliExpress token refresh failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
