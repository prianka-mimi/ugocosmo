<?php

namespace App\Services;

use App\DTOs\Etsy\EtsyTokenData;
use App\Exceptions\Etsy\EtsyOAuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth 2.0 Authorization Code + PKCE flow for connecting a seller's Etsy
 * account (https://www.etsy.com/oauth/connect). Mirrors
 * AliExpressOAuthService: stateless, multi-seller, signed single-use state
 * that binds the round-trip to the authenticated Castilo user.
 *
 * Official endpoints (developer.etsy.com → Authentication):
 *   Authorize: https://www.etsy.com/oauth/connect      (GET, browser)
 *   Token:     https://api.etsy.com/v3/public/oauth/token (POST)
 */
class EtsyOAuthService
{
    public function generateState(int $userId, int $ttlMinutes = 30, ?string $appRedirect = null): string
    {
        $payload = $this->encodeState([
            'user_id' => $userId,
            'exp' => now()->addMinutes($ttlMinutes)->getTimestamp(),
            'nonce' => Str::random(32),
            'code_verifier' => $this->generateCodeVerifier(),
            'app_redirect' => $appRedirect,
        ]);

        return $payload.'.'.$this->signState($payload);
    }

    /**
     * Validate a state token returned by Etsy: signature, expiry, single-use
     * nonce. Returns the decoded payload or null.
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
            || ! isset($decoded['user_id'], $decoded['exp'], $decoded['nonce'], $decoded['code_verifier'])
            || (int) $decoded['exp'] < now()->getTimestamp()
        ) {
            return null;
        }

        // Single-use enforcement via cache-backed nonce (CSRF/replay guard).
        $nonceKey = 'etsy_oauth_state_nonce_'.$decoded['nonce'];
        if (! Cache::add($nonceKey, true, now()->addMinutes($ttl = 35))) {
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
     * PKCE code verifier: 43-128 unreserved characters (RFC 7636).
     */
    protected function generateCodeVerifier(): string
    {
        return Str::random(64);
    }

    /**
     * PKCE S256 code challenge derived from the verifier.
     */
    public function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * Build the Etsy authorization URL (browser redirect target).
     */
    public function buildAuthorizationUrl(string $state): string
    {
        $config = (array) config('services.etsy');
        $apiKey = (string) ($config['api_key'] ?? '');

        if ($apiKey === '') {
            throw new EtsyOAuthException('Etsy is not configured. Set ETSY_API_KEY in your .env.', 500);
        }

        $payload = $this->decodeState(explode('.', $state)[0]);
        $codeVerifier = (string) ($payload['code_verifier'] ?? '');

        if ($codeVerifier === '') {
            throw new EtsyOAuthException('OAuth state is missing the PKCE code verifier.', 500);
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $apiKey,
            'redirect_uri' => (string) ($config['redirect_uri'] ?? ''),
            'scope' => (string) ($config['scopes'] ?? 'shops_r listings_r listings_w'),
            'state' => $state,
            'code_challenge' => $this->codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ]);

        return rtrim((string) ($config['oauth_authorize_url'] ?? 'https://www.etsy.com/oauth/connect'), '/').'?'.$query;
    }

    /**
     * Exchange the authorization code for tokens using the PKCE verifier
     * stored inside the state.
     *
     * @throws EtsyOAuthException
     */
    public function exchangeCode(string $code, string $state): EtsyTokenData
    {
        $payload = $this->validateState($state);

        if (! $payload) {
            throw new EtsyOAuthException('OAuth state is invalid or expired.', 400);
        }

        $config = (array) config('services.etsy');

        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'client_id' => (string) ($config['api_key'] ?? ''),
            'redirect_uri' => (string) ($config['redirect_uri'] ?? ''),
            'code' => $code,
            'code_verifier' => (string) $payload['code_verifier'],
        ]);
    }

    /**
     * Refresh tokens using the stored refresh token. Etsy does NOT rotate
     * refresh tokens — the same refresh token stays valid for 90 days.
     *
     * @throws EtsyOAuthException
     */
    public function refreshToken(string $refreshToken): EtsyTokenData
    {
        $config = (array) config('services.etsy');

        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'client_id' => (string) ($config['api_key'] ?? ''),
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * POST to the Etsy token endpoint (application/x-www-form-urlencoded).
     *
     * @param  array<string, string>  $params
     *
     * @throws EtsyOAuthException
     */
    protected function requestToken(array $params): EtsyTokenData
    {
        $tokenUrl = (string) (config('services.etsy.oauth_token_url') ?? 'https://api.etsy.com/v3/public/oauth/token');

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.etsy.request_timeout', 30))
                ->post($tokenUrl, $params);
        } catch (ConnectionException $e) {
            Log::error('EtsyOAuthService: token endpoint unreachable: '.$e->getMessage());

            throw new EtsyOAuthException('Could not reach the Etsy token endpoint.', 502, $e);
        }

        if ($response->status() === 429) {
            throw new EtsyOAuthException('Etsy rate limit exceeded. Try again shortly.', 429);
        }

        if (! $response->successful()) {
            $body = (array) $response->json();
            $message = (string) ($body['error_message'] ?? $body['error'] ?? $response->body());

            Log::warning('EtsyOAuthService: token request failed', [
                'status' => $response->status(),
                'grant_type' => $params['grant_type'] ?? 'unknown',
            ]);

            throw new EtsyOAuthException(
                "Etsy token request failed (HTTP {$response->status()}): {$message}",
                $response->status()
            );
        }

        $data = (array) $response->json();

        if (empty($data['access_token'])) {
            throw new EtsyOAuthException('Etsy returned no access token.', 502, null, $data);
        }

        return EtsyTokenData::fromResponse($data);
    }
}
