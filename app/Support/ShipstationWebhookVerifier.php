<?php

namespace App\Support;

use App\Exceptions\ShipEngine\ShipstationWebhookVerificationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies ShipStation (formerly ShipEngine) webhook signatures using RSA-SHA256.
 *
 * ShipStation signs outgoing webhooks with an RSA key pair. The webhook
 * request carries three headers:
 *
 *   x-shipengine-rsa-sha256-key-id    — identifies which JWKS key to use
 *   x-shipengine-rsa-sha256-signature — base64-encoded RSA-SHA256 signature
 *   x-shipengine-timestamp            — ISO 8601 timestamp (replay protection)
 *
 * The signed payload is: timestamp + "." + raw_request_body
 *
 * The public key is fetched from the JWKS endpoint (https://api.shipengine.com/jwks)
 * and cached with ETag/If-None-Match support. On an unknown key ID the cache
 * is refreshed once before failing.
 *
 * A shared-secret fallback (X-ShipEngine-Signature header) remains supported
 * for local development / testing where the provider does not sign payloads,
 * mirroring the Stripe/Shopify webhook pattern already in this project.
 */
class ShipstationWebhookVerifier
{
    public const JWKS_URL = 'https://api.shipengine.com/jwks';

    public const DEFAULT_TIMESTAMP_TOLERANCE = 300; // 5 minutes, per official docs

    public const DEFAULT_JWKS_CACHE_TTL = 86400; // 24 hours

    private const JWKS_CACHE_KEY = 'shipengine:jwks_keys';

    private const JWKS_ETAG_KEY = 'shipengine:jwks_etag';

    public function __construct(
        protected string $jwkSetUrl = self::JWKS_URL,
        protected int $timestampTolerance = self::DEFAULT_TIMESTAMP_TOLERANCE,
        protected int $jwksCacheTtl = self::DEFAULT_JWKS_CACHE_TTL,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('services.shipengine', []);

        return new self(
            jwkSetUrl: (string) ($config['jwks_url'] ?? self::JWKS_URL),
            timestampTolerance: (int) ($config['webhook_timestamp_tolerance'] ?? self::DEFAULT_TIMESTAMP_TOLERANCE),
            jwksCacheTtl: (int) ($config['jwks_cache_ttl'] ?? self::DEFAULT_JWKS_CACHE_TTL),
        );
    }

    /**
     * Verify a webhook request.
     *
     * @param  string  $rawBody  the exact, unmodified request body (getContent)
     * @param  array<string, string|null>  $headers  header bag (keys lowercased)
     *
     * @throws ShipstationWebhookVerificationException on any failure
     */
    public function verify(string $rawBody, array $headers, ?int $now = null): void
    {
        $now ??= time();

        $keyId = $this->header($headers, 'x-shipengine-rsa-sha256-key-id');
        $signature = $this->header($headers, 'x-shipengine-rsa-sha256-signature');
        $timestamp = $this->header($headers, 'x-shipengine-timestamp');

        // ── Fallback: shared-secret header (local/dev testing) ──────
        // If a shared secret is configured, accept the legacy header even
        // when RSA headers are absent (e.g. local development, CI).
        $sharedSecret = (string) (config('shipengine.webhook_secret') ?: config('services.shipengine.webhook_secret', ''));
        if ($sharedSecret !== '') {
            $headerName = (string) (config('shipengine.webhook_header') ?: config('services.shipengine.webhook_header', 'x-shipengine-signature'));
            $provided = $this->header($headers, $headerName) ?? $this->header($headers, 'x-shipengine-signature');

            if ($provided !== null) {
                $computedHmac = hash_hmac('sha256', $rawBody, $sharedSecret);
                if (hash_equals($sharedSecret, $provided) || hash_equals($computedHmac, $provided) || hash_equals("sha256={$computedHmac}", $provided)) {
                    return;
                }

                throw new ShipstationWebhookVerificationException(
                    'Invalid shared-secret or HMAC signature.',
                    ShipstationWebhookVerificationException::INVALID_SIGNATURE
                );
            }
        }

        // ── Missing required RSA-SHA256 headers → 404 ────────────────
        if (! $keyId || ! $signature || ! $timestamp) {
            throw new ShipstationWebhookVerificationException(
                'Missing required ShipStation webhook signature headers.',
                ShipstationWebhookVerificationException::MISSING_HEADERS
            );
        }

        // ── Timestamp validation (replay protection) → 400 ──────────
        if (! $this->isTimestampValid((string) $timestamp, $now)) {
            throw new ShipstationWebhookVerificationException(
                'Webhook timestamp is outside the acceptable tolerance window.',
                ShipstationWebhookVerificationException::INVALID_TIMESTAMP
            );
        }

        // ── Signature verification → 401 ────────────────────────────
        $publicKey = $this->getPublicKey((string) $keyId);

        if ($publicKey === null) {
            throw new ShipstationWebhookVerificationException(
                'Unable to resolve the public key for this webhook signature.',
                ShipstationWebhookVerificationException::INVALID_SIGNATURE
            );
        }

        $signedPayload = (string) $timestamp.'.'.$rawBody;
        $decodedSignature = base64_decode((string) $signature, true);

        if ($decodedSignature === false) {
            throw new ShipstationWebhookVerificationException(
                'Invalid signature encoding.',
                ShipstationWebhookVerificationException::INVALID_SIGNATURE
            );
        }

        $result = openssl_verify($signedPayload, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($result !== 1) {
            throw new ShipstationWebhookVerificationException(
                'Webhook signature verification failed.',
                ShipstationWebhookVerificationException::INVALID_SIGNATURE
            );
        }
    }

    /**
     * @param  array<string, string|null>  $headers
     * @param  string  $name  lowercased header name
     */
    protected function header(array $headers, string $name): ?string
    {
        $name = strtolower($name);

        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name && $value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function isTimestampValid(string $timestamp, int $now): bool
    {
        $ts = $this->parseTimestamp($timestamp);

        if ($ts === null) {
            return false;
        }

        // Reject timestamps more than the tolerance in the future or past.
        // A small absolute difference is allowed for clock skew.
        return abs($now - $ts) <= $this->timestampTolerance;
    }

    /**
     * Parse a webhook timestamp into a Unix epoch.
     *
     * ShipStation sends ISO 8601 timestamps (e.g. "2025-10-02T04:51:00Z").
     * Numeric Unix timestamps are accepted for backward compatibility with
     * the shared-secret fallback path and local development.
     *
     * @return int|null Unix epoch, or null if the timestamp is malformed
     */
    protected function parseTimestamp(string $timestamp): ?int
    {
        // Numeric Unix timestamp (backward compatibility).
        if (ctype_digit($timestamp)) {
            return (int) $timestamp;
        }

        // ISO 8601 timestamp (official ShipStation format). strtotime handles
        // "2025-10-02T04:51:00Z", timezone offsets ("+00:00"), and various
        // ISO 8601 variants.
        $ts = strtotime($timestamp);

        return $ts === false ? null : $ts;
    }

    /**
     * Resolve a JWK by key ID, refreshing the cache when the key is unknown.
     */
    protected function getPublicKey(string $keyId): ?string
    {
        $jwks = $this->fetchJwks();

        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (($jwk['kid'] ?? null) === $keyId) {
                return $this->jwkToPem($jwk);
            }
        }

        // Unknown key — refresh JWKS once and retry (key rotation).
        $this->clearJwkCache();
        $jwks = $this->fetchJwks(forceRefresh: true);

        foreach ($jwks['keys'] ?? [] as $jwk) {
            if (($jwk['kid'] ?? null) === $keyId) {
                return $this->jwkToPem($jwk);
            }
        }

        return null;
    }

    /**
     * Fetch and cache the JWKS, honouring ETag/If-None-Match.
     */
    protected function fetchJwks(bool $forceRefresh = false): array
    {
        $cached = Cache::get(self::JWKS_CACHE_KEY);

        if (! $forceRefresh && is_array($cached)) {
            return $cached;
        }

        $request = Http::timeout(10)->withOptions([
            'curl' => [
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ],
        ]);

        $cachedEtag = Cache::get(self::JWKS_ETAG_KEY);
        if ($cachedEtag) {
            $request->withHeaders(['If-None-Match' => $cachedEtag]);
        }

        try {
            $response = $request->get($this->jwkSetUrl);

            if ($response->status() === 304 && $cached !== null) {
                return $cached;
            }

            $jwks = $response->json();

            if (! is_array($jwks) || ! isset($jwks['keys'])) {
                Log::channel('shipengine')->warning('JWKS response missing keys array', [
                    'status' => $response->status(),
                    'url' => $this->jwkSetUrl,
                ]);

                return [];
            }

            Cache::put(self::JWKS_CACHE_KEY, $jwks, now()->addSeconds($this->jwksCacheTtl));

            $etag = $response->header('ETag');
            if ($etag) {
                Cache::put(self::JWKS_ETAG_KEY, $etag, now()->addSeconds($this->jwksCacheTtl));
            }

            return $jwks;
        } catch (\Throwable $e) {
            Log::channel('shipengine')->warning('Failed to fetch JWKS', [
                'error' => $e->getMessage(),
                'url' => $this->jwkSetUrl,
            ]);

            // Fall back to cached JWKS even if expired.
            $cached = Cache::get(self::JWKS_CACHE_KEY);

            return is_array($cached) ? $cached : [];
        }
    }

    protected function clearJwkCache(): void
    {
        Cache::forget(self::JWKS_CACHE_KEY);
        Cache::forget(self::JWKS_ETAG_KEY);
    }

    /**
     * Convert a JWK (JSON Web Key) to a PEM-encoded RSA public key.
     *
     * @param  array<string, mixed>  $jwk
     */
    protected function jwkToPem(array $jwk): ?string
    {
        if (! isset($jwk['n'], $jwk['e']) || ! is_string($jwk['n']) || ! is_string($jwk['e'])) {
            return null;
        }

        $n = $this->base64UrlDecode($jwk['n']);
        $e = $this->base64UrlDecode($jwk['e']);

        if ($n === false || $e === false || $n === '' || $e === '') {
            return null;
        }

        $inner = $this->derEncode(0x30, $this->derEncode(0x02, $this->ensurePositive($n)).$this->derEncode(0x02, $this->ensurePositive($e)));

        $oid = "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
        $algorithm = $this->derEncode(0x30, $this->derEncode(0x06, $oid).$this->derEncode(0x05, ''));
        $bitString = $this->derEncode(0x03, "\x00".$inner);
        $der = $this->derEncode(0x30, $algorithm.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    protected function derEncode(int $tag, string $data): string
    {
        $length = strlen($data);

        if ($length <= 0x7F) {
            return chr($tag).chr($length).$data;
        }

        $lengthBytes = '';
        while ($length > 0) {
            $lengthBytes = chr($length & 0xFF).$lengthBytes;
            $length >>= 8;
        }

        return chr($tag).chr(0x80 | strlen($lengthBytes)).$lengthBytes.$data;
    }

    /**
     * Ensure the integer bytes are interpreted as positive (prepend 0x00).
     */
    protected function ensurePositive(string $bytes): string
    {
        if (strlen($bytes) > 0 && (ord($bytes[0]) & 0x80)) {
            return "\x00".$bytes;
        }

        return $bytes;
    }

    protected function base64UrlDecode(string $input): string|false
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($input, '-_', '+/'), true);
    }
}
