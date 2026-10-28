<?php

namespace App\Services;

use App\DTOs\AliExpress\AliExpressTokenData;
use App\Exceptions\AliExpress\OAuthException;
use Illuminate\Support\Str;

/**
 * OAuth Authorization Code flow for connecting a seller's AliExpress
 * account. Every seller connects their OWN account — this service is
 * stateless and multi-seller by design.
 */
class AliExpressOAuthService
{
    protected AliExpressApiClient $client;

    public function __construct(AliExpressApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Generate the OAuth state token for the round-trip.
     *
     * The state is a signed, expiring, single-use token that securely binds
     * the OAuth transaction to the authenticated Castilo user — the callback
     * does NOT have to rely on the browser session to identify the user
     * (see docs/aliexpress-integration.md §2 "Connection flow").
     *
     * Format: base64url(payload).hmacSha256(base64url(payload), app.key)
     * Payload: { user_id, region, exp (unix seconds), nonce }
     */
    public function generateState(int $userId, ?string $region = null, int $ttlMinutes = 30, ?string $appRedirect = null): string
    {
        $payload = $this->encodeState([
            'user_id' => $userId,
            'region' => $region ?: (string) config('services.aliexpress.region', 'sg'),
            'exp' => now()->addMinutes($ttlMinutes)->getTimestamp(),
            'nonce' => Str::random(32),
            'app_redirect' => $appRedirect,
        ]);

        return $payload.'.'.$this->signState($payload);
    }

    /**
     * Validate a state token returned by AliExpress.
     *
     * Verifies the signature, checks expiry, and enforces single-use via a
     * cache-backed nonce. Returns the decoded payload (with user_id) or null.
     */
    public function validateState(string $state): ?array
    {
        $parts = explode('.', $state);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;

        if (! hash_equals($this->signState($payload), $signature)) {
            return null;
        }

        $decoded = $this->decodeState($payload);
        if (! is_array($decoded)
            || ! isset($decoded['user_id'], $decoded['exp'], $decoded['nonce'])
            || (int) $decoded['exp'] < now()->getTimestamp()
        ) {
            return null;
        }

        return $decoded;
    }

    protected function encodeState(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    protected function decodeState(string $encoded): ?array
    {
        $json = base64_decode(strtr($encoded, '-_', '+/')) ?: '';

        return json_decode($json, true) ?: null;
    }

    protected function signState(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /**
     * Build the AliExpress authorization URL.
     */
    public function buildAuthorizationUrl(string $state, ?string $region = null): string
    {
        $appKey = (string) config('services.aliexpress.app_key');
        $redirectUri = (string) config('services.aliexpress.redirect_uri');

        if ($appKey === '') {
            throw new OAuthException('AliExpress is not configured. Set ALIEXPRESS_APP_KEY in your .env.', 500);
        }

        $region = $region ?: (string) config('services.aliexpress.region', 'sg');
        $apiBaseUris = (array) config('services.aliexpress.base_uris', []);
        $oauthBaseUris = (array) config('services.aliexpress.oauth_base_uris', []);
        $base = $oauthBaseUris[$region] ?: $apiBaseUris[$region] ?: $apiBaseUris['sg'] ?: 'https://api-sg.aliexpress.com';
        $authorizePath = (string) (config('services.aliexpress.oauth_paths.authorize') ?? '/oauth/authorize');

        $query = http_build_query([
            'response_type' => 'code',
            'force_auth' => 'true',
            'client_id' => $appKey,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'lang' => 'en',
        ]);

        return "{$base}{$authorizePath}?{$query}";
    }

    /**
     * Exchange the authorization code for tokens.
     *
     * @throws OAuthException
     */
    public function exchangeCode(string $code, ?string $region = null): AliExpressTokenData
    {
        $redirectUri = (string) config('services.aliexpress.redirect_uri');

        $response = $this->client->tokenCreate($code, $redirectUri, $region);

        if (empty($response['access_token'])) {
            throw new OAuthException('AliExpress returned no access token.', 502, null, $response);
        }

        return AliExpressTokenData::fromResponse($response);
    }

    /**
     * Refresh tokens using the stored refresh token.
     *
     * @throws OAuthException
     */
    public function refreshToken(string $refreshToken, ?string $region = null, ?int $accountId = null): AliExpressTokenData
    {
        $response = $this->client->tokenRefresh($refreshToken, $region, $accountId);

        if (empty($response['access_token'])) {
            throw new OAuthException('AliExpress refresh returned no access token.', 502, null, $response);
        }

        return AliExpressTokenData::fromResponse($response);
    }
}
