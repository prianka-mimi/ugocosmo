<?php

namespace App\Services;

use App\Exceptions\Etsy\EtsyOAuthException;
use App\Models\EtsyAccount;
use App\Repositories\EtsyAccountRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Centralized Etsy token management — mirrors AliExpressTokenManager.
 *
 * Guarantees a valid access token for API calls:
 *  - returns the decrypted token when still valid
 *  - automatically refreshes via the refresh token when expired
 *  - uses a distributed lock so concurrent jobs never double-refresh
 *  - Etsy does NOT rotate refresh tokens, so a double-refresh cannot burn
 *    credentials the way it can with AliExpress — but the lock still avoids
 *    pointless duplicate network calls.
 */
class EtsyTokenManager
{
    public const LOCK_SECONDS = 30;

    public function __construct(
        protected EtsyAccountRepository $accounts,
        protected EtsyOAuthService $oauth,
    ) {}

    /**
     * Resolve a valid (non-expired, decrypted) access token.
     *
     * @throws EtsyOAuthException when the token is expired and cannot be refreshed
     */
    public function ensureValidToken(EtsyAccount $account): string
    {
        if (! $account->isActive()) {
            throw new \Exception('Etsy account is disabled. Reconnect it first.', 403);
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
     * @throws EtsyOAuthException
     */
    public function refresh(EtsyAccount $account): string
    {
        $lock = Cache::lock("etsy_token_refresh_{$account->id}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            // Another process is refreshing — wait briefly and re-read.
            sleep(2);
            $fresh = $this->accounts->findByIdRaw($account->id);
            if ($fresh && ! $fresh->isTokenExpired()) {
                return $this->accounts->decryptAccessToken($fresh);
            }

            throw new EtsyOAuthException('Etsy token refresh already in progress. Try again shortly.', 429);
        }

        // Double-check after acquiring the lock.
        $account->refresh();
        if (! $account->isTokenExpired()) {
            $lock->release();

            return $this->accounts->decryptAccessToken($account);
        }

        try {
            $refreshToken = $this->accounts->decryptRefreshToken($account);

            if (! $refreshToken || $account->isRefreshTokenExpired()) {
                throw new EtsyOAuthException(
                    'Your Etsy connection has expired. Please reconnect your account.',
                    401
                );
            }

            $data = $this->oauth->refreshToken($refreshToken);

            $this->accounts->update($account, [
                'access_token' => $data->accessToken,
                // Etsy does not rotate refresh tokens: keep the stored one.
                'refresh_token' => $refreshToken,
                'token_expires_at' => $data->tokenExpiresAt(),
                // Refresh responses do not extend the 90-day refresh window
                // (it is fixed from the original grant), so keep the earlier
                // expiry if one is already stored.
                'refresh_token_valid_until' => $account->refresh_token_valid_until ?? $data->refreshTokenValidUntil(),
                'last_error' => null,
            ]);

            Log::info('Etsy token refreshed automatically', [
                'account_id' => $account->id,
                'profile_id' => $account->profile_id,
            ]);

            return $data->accessToken;
        } catch (EtsyOAuthException $e) {
            $this->accounts->update($account, [
                'last_error' => 'Token refresh failed: '.$e->getMessage(),
            ]);

            Log::error('Etsy token refresh failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
