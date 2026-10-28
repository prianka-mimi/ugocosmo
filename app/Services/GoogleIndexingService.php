<?php

namespace App\Services;

use App\Exceptions\GoogleMerchant\GoogleMerchantApiException;
use App\Exceptions\GoogleMerchant\GoogleMerchantAuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Indexing API client.
 *
 * Notifies Google to crawl or de-index a URL immediately.
 * Designed for JobPosting and other indexable content types.
 *
 * API reference: https://developers.google.com/search/apis/indexing-api/v3/quickstart
 */
class GoogleIndexingService
{
    protected string $serviceAccountJsonPath;

    protected string $endpoint = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

    protected string $scope = 'https://www.googleapis.com/auth/indexing';

    public function __construct()
    {
        $this->serviceAccountJsonPath = (string) config(
            'services.google_indexing.service_account_json',
            config('services.google_merchant.service_account_json', '')
        );
    }

    /**
     * Notify Google that a URL has been updated (publish or re-crawl).
     */
    public function notifyUpdated(string $url): void
    {
        $this->publish($url, 'URL_UPDATED');
    }

    /**
     * Notify Google that a URL has been deleted (de-index).
     */
    public function notifyDeleted(string $url): void
    {
        $this->publish($url, 'URL_DELETED');
    }

    // ─── Core ────────────────────────────────────────────────────────

    protected function publish(string $url, string $type): void
    {
        $accessToken = $this->getAccessToken();

        try {
            $response = Http::withToken($accessToken)
                ->timeout(20)
                ->post($this->endpoint, [
                    'url' => $url,
                    'type' => $type,
                ]);

            if ($response->failed()) {
                $body = $response->json();
                $message = $body['error']['message'] ?? "HTTP {$response->status()}";

                Log::channel('google_merchant')->error('Google Indexing API error', [
                    'url' => $url,
                    'type' => $type,
                    'status' => $response->status(),
                    'message' => $message,
                ]);

                throw new GoogleMerchantApiException(
                    "Google Indexing API failed [{$type}]: {$message}",
                    $response->status()
                );
            }

            Log::channel('google_merchant')->info('Google Indexing API notified', [
                'url' => $url,
                'type' => $type,
            ]);
        } catch (ConnectionException $e) {
            Log::channel('google_merchant')->error('Google Indexing API connection failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new GoogleMerchantApiException(
                'Google Indexing API connection failed: '.$e->getMessage(),
                502,
                $e
            );
        }
    }

    // ─── Auth ────────────────────────────────────────────────────────

    public function getAccessToken(): string
    {
        return Cache::remember('google_indexing_access_token', now()->addMinutes(50), function () {
            $credentials = $this->loadServiceAccountCredentials();

            $jwt = $this->createJwt($credentials);

            $response = Http::timeout(15)
                ->asForm()
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

            if ($response->failed()) {
                throw new GoogleMerchantAuthException(
                    'Failed to obtain Google Indexing API access token.',
                    401
                );
            }

            return $response->json('access_token', '');
        });
    }

    protected function createJwt(array $credentials): string
    {
        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));

        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => $this->scope,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

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
                'Google Indexing service account JSON not configured. '
                .'Set GOOGLE_INDEXING_SERVICE_ACCOUNT_JSON in your .env.',
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
                'Invalid service account JSON. Must contain client_email and private_key.',
                503
            );
        }

        return $contents;
    }
}
