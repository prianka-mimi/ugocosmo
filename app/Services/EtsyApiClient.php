<?php

namespace App\Services;

use App\Exceptions\Etsy\EtsyApiException;
use App\Exceptions\Etsy\EtsyRateLimitException;
use App\Models\EtsyAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Low-level HTTP client for the Etsy Open API v3.
 *
 * Authentication (official docs → Authentication):
 *  - `x-api-key: <keystring>:<shared_secret>` on every request
 *  - `Authorization: Bearer <access_token>` additionally on endpoints that
 *    access private user data (seller-connected requests)
 *
 * Handles:
 *  - Header/timeout construction
 *  - Typed error mapping (429 → EtsyRateLimitException)
 *  - Transparent retry for rate limits / 5xx / network failures
 *  - Error logging that never includes the API key or tokens
 */
class EtsyApiClient
{
    protected string $apiKey;

    protected string $baseUrl;

    protected int $timeout;

    protected int $maxRetries;

    protected int $retryDelaySeconds;

    public function __construct()
    {
        $config = config('services.etsy') ?? [];

        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://openapi.etsy.com/v3/application'), '/');
        $this->timeout = (int) ($config['request_timeout'] ?? 30);
        $this->maxRetries = max(1, (int) ($config['max_retries'] ?? 3));
        $this->retryDelaySeconds = (int) ($config['retry_delay_seconds'] ?? 2);
    }

    /**
     * Perform a GET request against the Etsy Open API and return the
     * decoded JSON payload.
     *
     * @param  array<string, scalar|null>  $query
     * @param  EtsyAccount|null  $account  when provided, the request is made
     *                                     as the connected seller (Bearer token,
     *                                     auto-refreshed when expired)
     * @return array<string, mixed>
     *
     * @throws EtsyRateLimitException when rate-limited beyond the retry budget
     * @throws EtsyApiException for other API/HTTP failures
     */
    public function get(string $path, array $query = [], ?EtsyAccount $account = null): array
    {
        $this->assertConfigured();

        $query = array_filter($query, fn ($value) => $value !== null);

        return $this->withRetry(function () use ($path, $query, $account) {
            $response = $this->request($account)
                ->timeout($this->timeout)
                ->get($this->url($path), $query);

            $this->guardResponse($response, $path);

            return (array) $response->json();
        }, $path);
    }

    /**
     * Whether the platform API key is configured.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Build the Http pending request with the required auth headers.
     * When an Etsy account is given, the seller's (auto-refreshed) Bearer
     * token is attached on top of the platform x-api-key header.
     */
    protected function request(?EtsyAccount $account): PendingRequest
    {
        $sharedSecret = (string) config('services.etsy.shared_secret', '');

        $headers = [
            // Official format: keystring:shared_secret. A bare keystring is
            // still accepted for apps issued before the shared-secret era.
            'x-api-key' => $sharedSecret !== '' ? $this->apiKey.':'.$sharedSecret : $this->apiKey,
            'Accept' => 'application/json',
        ];

        $pending = Http::withHeaders($headers);

        if ($account !== null) {
            $token = app(EtsyTokenManager::class)->ensureValidToken($account);
            $pending = $pending->withToken($token);
        }

        return $pending;
    }

    // ─── Internals ─────────────────────────────────────────────────

    protected function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * Map an HTTP failure to a typed exception.
     *
     * @throws EtsyRateLimitException
     * @throws EtsyApiException
     */
    protected function guardResponse(Response $response, string $path): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 429) {
            throw new EtsyRateLimitException(
                'Etsy rate limit exceeded (HTTP 429).',
                429,
                null,
                ['path' => $path]
            );
        }

        // Etsy returns {error: "..."} or {error_message: "..."} on failure.
        $body = (array) $response->json();
        $message = (string) ($body['error_message'] ?? $body['error'] ?? '');

        throw new EtsyApiException(
            $message !== ''
                ? "Etsy API request to {$path} failed (HTTP {$response->status()}): {$message}"
                : "Etsy API request to {$path} failed (HTTP {$response->status()}).",
            $response->status(),
            null,
            ['path' => $path, 'status' => $response->status()]
        );
    }

    /**
     * Retry transient failures (429, 5xx, network) with linear backoff.
     * 4xx failures are permanent and never retried.
     *
     * @throws EtsyApiException
     */
    protected function withRetry(callable $callable, string $path): array
    {
        $attempt = 1;

        while (true) {
            try {
                return $callable();
            } catch (EtsyRateLimitException|ConnectionException $e) {
                if ($attempt >= $this->maxRetries) {
                    Log::warning('EtsyApiClient: retry budget exhausted', [
                        'path' => $path,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    throw $e instanceof ConnectionException
                        ? new EtsyApiException('Etsy connection failed: '.$e->getMessage(), 502, $e)
                        : $e;
                }

                $delay = $this->retryDelaySeconds * $attempt;
                Log::info('EtsyApiClient: transient failure, retrying', [
                    'path' => $path,
                    'attempt' => $attempt,
                    'delay' => $delay,
                ]);
                sleep($delay);
                $attempt++;
            } catch (EtsyApiException $e) {
                // 5xx is transient; 4xx (invalid key, bad request, not found) is not.
                if ($e->getCode() < 500) {
                    throw $e;
                }

                if ($attempt >= $this->maxRetries) {
                    Log::warning('EtsyApiClient: retry budget exhausted for 5xx', [
                        'path' => $path,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }

                $delay = $this->retryDelaySeconds * $attempt;
                Log::info('EtsyApiClient: 5xx transient failure, retrying', [
                    'path' => $path,
                    'attempt' => $attempt,
                    'delay' => $delay,
                ]);
                sleep($delay);
                $attempt++;
            }
        }
    }

    protected function assertConfigured(): void
    {
        if ($this->apiKey === '') {
            throw new EtsyApiException(
                'Etsy is not configured. Set ETSY_API_KEY in your .env.',
                500
            );
        }
    }
}
