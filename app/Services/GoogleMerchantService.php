<?php

namespace App\Services;

use App\Exceptions\GoogleMerchant\GoogleMerchantApiException;
use App\Exceptions\GoogleMerchant\GoogleMerchantAuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * REST client for the Google Merchant API (v1).
 *
 * Manages product lifecycle (insert, update, delete, list) against the
 * Merchant Center account via the ProductInputs sub-API. Uses a Google
 * service account for authentication.
 *
 * API reference: https://developers.google.com/merchant/api/reference/rest
 * ProductInputs: https://developers.google.com/merchant/api/guides/products/add-manage
 */
class GoogleMerchantService
{
    protected string $merchantId;

    protected string $dataSourceId;

    protected string $serviceAccountJsonPath;

    protected string $baseUrl = 'https://merchantapi.googleapis.com';

    protected int $timeout = 30;

    protected int $connectTimeout = 10;

    public function __construct()
    {
        $config = config('services.google_merchant');

        $this->merchantId = (string) ($config['merchant_id'] ?? '');
        $this->dataSourceId = (string) ($config['data_source_id'] ?? '');
        $this->serviceAccountJsonPath = (string) ($config['service_account_json'] ?? '');
        $this->timeout = (int) ($config['timeout'] ?? 30);
        $this->connectTimeout = (int) ($config['connect_timeout'] ?? 10);
    }

    // ─── Product operations ─────────────────────────────────────────

    /**
     * Insert or update a product in Google Merchant Center.
     *
     * @param  array<string, mixed>  $productInput  ProductInput resource fields
     * @return array<string, mixed> The created/updated ProductInput resource
     */
    public function insertProduct(array $productInput): array
    {
        $this->assertConfigured();

        $parent = "accounts/{$this->merchantId}";
        $dataSource = "accounts/{$this->merchantId}/dataSources/{$this->dataSourceId}";

        return $this->request('post', "/products/v1/{$parent}/productInputs:insert", [
            'dataSource' => $dataSource,
            'productInput' => $productInput,
        ]);
    }

    /**
     * Delete a product from Google Merchant Center.
     *
     * @param  string  $offerId  The offer ID (your product SKU)
     * @param  string  $contentLanguage  e.g. 'en'
     * @param  string  $feedLabel  e.g. 'US'
     */
    public function deleteProduct(string $offerId, string $contentLanguage = 'en', string $feedLabel = 'US'): void
    {
        $this->assertConfigured();

        $parent = "accounts/{$this->merchantId}";
        $name = "{$parent}/productInputs/{$contentLanguage}~{$feedLabel}~{$offerId}";

        $this->request('delete', "/products/v1/{$name}");
    }

    /**
     * Get a product's status from Google Merchant Center.
     *
     * @return array<string, mixed> The Product resource with productStatus
     */
    public function getProduct(string $offerId, string $contentLanguage = 'en', string $feedLabel = 'US'): array
    {
        $this->assertConfigured();

        $parent = "accounts/{$this->merchantId}";
        $name = "{$parent}/products/{$contentLanguage}~{$feedLabel}~{$offerId}";

        return $this->request('get', "/products/v1/{$name}");
    }

    /**
     * List all products in the Merchant Center account.
     *
     * @param  int  $pageSize  Max results per page (1-250)
     * @param  string|null  $pageToken  Token for pagination
     * @return array{products: array, nextPageToken: string|null}
     */
    public function listProducts(int $pageSize = 250, ?string $pageToken = null): array
    {
        $this->assertConfigured();

        $parent = "accounts/{$this->merchantId}";

        $params = ['pageSize' => $pageSize];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        return $this->request('get', "/products/v1/{$parent}/products", query: $params);
    }

    // ─── Authentication ──────────────────────────────────────────────

    /**
     * Get a valid OAuth2 access token for the service account.
     * Cached for 50 minutes (tokens expire after 60 minutes).
     */
    public function getAccessToken(): string
    {
        $cacheKey = 'google_merchant_access_token';

        return Cache::remember($cacheKey, now()->addMinutes(50), function () {
            $credentials = $this->loadServiceAccountCredentials();

            $jwt = $this->createJwt($credentials);

            $response = Http::timeout(15)
                ->asForm()
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if ($response->failed()) {
                Log::error('Google Merchant OAuth token failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new GoogleMerchantAuthException(
                    'Failed to obtain Google Merchant API access token.',
                    401
                );
            }

            $data = $response->json();

            return $data['access_token'] ?? '';
        });
    }

    // ─── Request core ────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>|null  $query
     * @return array<string, mixed>
     */
    protected function request(
        string $method,
        string $path,
        ?array $body = null,
        ?array $query = null,
    ): array {
        $accessToken = $this->getAccessToken();

        $client = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        $url = $this->baseUrl.$path;

        if ($query) {
            $url .= '?'.http_build_query($query);
        }

        $startedAt = microtime(true);

        try {
            $response = match (strtoupper($method)) {
                'DELETE' => $client->delete($url),
                'PUT' => $client->put($url, $body ?? []),
                'PATCH' => $client->patch($url, $body ?? []),
                'GET' => $client->get($url),
                default => $client->post($url, $body ?? []),
            };

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::channel('google_merchant')->debug('Google Merchant API call', [
                'method' => $method,
                'path' => $path,
                'http_status' => $response->status(),
                'duration_ms' => $durationMs,
            ]);

            return $this->parse($response);
        } catch (ConnectionException $e) {
            Log::error('Google Merchant API connection failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new GoogleMerchantApiException(
                'Google Merchant API connection failed: '.$e->getMessage(),
                502,
                $e
            );
        }
    }

    /**
     * Parse the API response and map errors to typed exceptions.
     */
    protected function parse($response): array
    {
        $status = $response->status();
        $decoded = json_decode($response->body(), true);

        if ($status >= 200 && $status < 300) {
            return is_array($decoded) ? $decoded : [];
        }

        $message = $decoded['error']['message'] ?? $decoded['error'] ?? "Google Merchant API error (HTTP {$status}).";
        $errorCode = $decoded['error']['code'] ?? $status;

        throw match (true) {
            in_array($status, [401, 403], true) => new GoogleMerchantAuthException($message, $status),
            default => new GoogleMerchantApiException($message, $status),
        };
    }

    // ─── JWT creation (service account) ──────────────────────────────

    /**
     * Create a signed JWT for the Google service account OAuth2 flow.
     */
    protected function createJwt(array $credentials): string
    {
        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));

        $claimSet = [
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/content',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $payload = $this->base64UrlEncode(json_encode($claimSet));
        $signingInput = "{$header}.{$payload}";

        $signature = '';
        openssl_sign($signingInput, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);

        return "{$signingInput}.{$this->base64UrlEncode($signature)}";
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function loadServiceAccountCredentials(): array
    {
        if ($this->serviceAccountJsonPath === '') {
            throw new GoogleMerchantAuthException(
                'Google Merchant service account JSON not configured. '
                .'Set GOOGLE_MERCHANT_SERVICE_ACCOUNT_JSON in your .env.',
                503
            );
        }

        $path = storage_path($this->serviceAccountJsonPath);

        if (! file_exists($path)) {
            throw new GoogleMerchantAuthException(
                "Service account JSON file not found: {$path}",
                503
            );
        }

        $contents = json_decode(file_get_contents($path), true);

        if (! is_array($contents) || empty($contents['client_email']) || empty($contents['private_key'])) {
            throw new GoogleMerchantAuthException(
                'Invalid service account JSON file. Must contain client_email and private_key.',
                503
            );
        }

        return $contents;
    }

    protected function assertConfigured(): void
    {
        if ($this->merchantId === '' || $this->dataSourceId === '') {
            throw new GoogleMerchantApiException(
                'Google Merchant is not configured. Set GOOGLE_MERCHANT_ID and '
                .'GOOGLE_MERCHANT_DATA_SOURCE_ID in your .env.',
                503
            );
        }
    }
}
