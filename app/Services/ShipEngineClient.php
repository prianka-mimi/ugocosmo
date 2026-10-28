<?php

namespace App\Services;

use App\Exceptions\ShipEngine\ShipEngineApiException;
use App\Exceptions\ShipEngine\ShipEngineAuthenticationException;
use App\Exceptions\ShipEngine\ShipEngineNotFoundException;
use App\Exceptions\ShipEngine\ShipEngineRateLimitException;
use App\Exceptions\ShipEngine\ShipEngineValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dedicated low-level HTTP client for the ShipEngine / ShipStation API (v1).
 *
 * Responsibilities:
 *  - Base URL + API-Key header authentication ('API-Key' and 'Content-Type: application/json')
 *  - JSON request/response handling, timeouts
 *  - Transparent retry for idempotent reads only (rates, tracking, carriers, address validation);
 *    NEVER retries label purchase or other operations that create real-world side effects
 *  - Error mapping to typed exceptions (auth, rate limit, validation, not found, 5xx, network)
 *  - Structured logging without credentials
 *
 * Verified contract (official docs, shipengine.com / docs.shipstation.com):
 *  - POST /v1/rates                       → rate quotes
 *  - POST /v1/labels                      → purchase a label
 *  - POST /v1/labels/rates/{rate_id}      → purchase a label from a rate
 *  - PUT  /v1/labels/{label_id}/void      → void a label
 *  - GET  /v1/tracking                    → tracking events
 *  - GET  /v1/carriers                    → connected carriers
 *  - POST /v1/addresses/validate          → address validation (Advanced plan+)
 *  - GET  /v1/environment/webhooks        → list configured webhooks
 *  - POST /v1/environment/webhooks        → register a webhook
 *  - DEL  /v1/environment/webhooks/{id}   → remove a webhook
 */
class ShipEngineClient
{
    protected string $apiKey;

    protected string $sandboxApiKey;

    protected string $baseUrl;

    protected int $timeout;

    protected int $connectTimeout;

    protected int $maxRetries;

    protected int $retryDelaySeconds;

    protected bool $sandboxMode;

    public function __construct()
    {
        $services = config('services.shipengine', []);
        $shipengine = config('shipengine', []);

        $this->sandboxMode = (bool) ($services['sandbox_mode'] ?? $shipengine['sandbox_mode'] ?? false);
        $this->apiKey = (string) ($services['api_key'] ?? $shipengine['api_key'] ?? '');
        $this->sandboxApiKey = (string) ($services['sandbox_api_key'] ?? $shipengine['sandbox_api_key'] ?? '');
        $this->baseUrl = rtrim((string) ($services['base_url'] ?? $shipengine['base_url'] ?? 'https://api.shipengine.com'), '/');
        $this->timeout = (int) ($services['timeout'] ?? $shipengine['timeout'] ?? 30);
        $this->connectTimeout = (int) ($services['connect_timeout'] ?? $shipengine['connect_timeout'] ?? 5);
        $this->maxRetries = max(1, (int) ($services['max_retries'] ?? $shipengine['max_retries'] ?? 3));
        $this->retryDelaySeconds = (int) ($services['retry_delay_seconds'] ?? $shipengine['retry_delay_seconds'] ?? 2);
    }

    // ─── Public API ─────────────────────────────────────────────────

    /**
     * Get shipping rate quotes for a shipment.
     *
     * @param  array<string, mixed>  $shipment  ship_to/ship_from/packages payload
     * @param  array<string, mixed>  $rateOptions  carrier_ids / service_codes filters
     * @return array<string, mixed> raw ShipEngine response
     */
    public function getRates(array $shipment, array $rateOptions = []): array
    {
        $this->assertConfigured();

        // rate_options must serialize as a JSON object, never an empty array []
        $rateOptions = $rateOptions === [] ? new \stdClass : $rateOptions;

        return $this->request('post', '/v1/rates', [
            'rate_options' => $rateOptions,
            'shipment' => $shipment,
        ], retryable: true, context: 'rates');
    }

    /**
     * Purchase a shipping label for an explicit carrier + service.
     * NEVER retried — a successful response means the label was purchased.
     *
     * @param  array<string, mixed>  $shipment  carrier_id, service_code, ship_to, ship_from, packages
     * @return array<string, mixed> raw ShipEngine label response
     */
    public function createLabel(array $shipment, string $labelFormat = 'pdf', string $labelLayout = '4x6', bool $testLabel = false): array
    {
        $this->assertConfigured();

        return $this->request('post', '/v1/labels', [
            'shipment' => $shipment,
            'label_format' => $labelFormat,
            'label_layout' => $labelLayout,
            'test_label' => $testLabel,
        ], retryable: false, context: 'create_label');
    }

    /**
     * Purchase a shipping label from a previously retrieved rate.
     * NEVER retried — a successful response means the label was purchased.
     *
     * @return array<string, mixed> raw ShipEngine label response
     */
    public function createLabelFromRate(string $rateId, string $labelFormat = 'pdf', string $labelLayout = '4x6', bool $testLabel = false): array
    {
        $this->assertConfigured();

        return $this->request('post', '/v1/labels/rates/'.urlencode($rateId), [
            'label_format' => $labelFormat,
            'label_layout' => $labelLayout,
            'test_label' => $testLabel,
        ], retryable: false, context: 'create_label');
    }

    /**
     * Void a previously purchased label.
     *
     * @return array<string, mixed> raw ShipEngine response
     */
    public function voidLabel(string $labelId): array
    {
        $this->assertConfigured();

        return $this->request('put', '/v1/labels/'.urlencode($labelId).'/void', [], retryable: false, context: 'void_label');
    }

    /**
     * Retrieve live tracking events for a carrier + tracking number.
     *
     * @return array<string, mixed> raw ShipEngine tracking response
     */
    public function getTracking(string $carrierCode, string $trackingNumber): array
    {
        $this->assertConfigured();

        return $this->request('get', '/v1/tracking', query: [
            'carrier_code' => $carrierCode,
            'tracking_number' => $trackingNumber,
        ], retryable: true, context: 'tracking');
    }

    /**
     * Start tracking a shipment and subscribe to webhook updates.
     */
    public function startTracking(string $carrierCode, string $trackingNumber): array
    {
        $this->assertConfigured();

        return $this->request('post', '/v1/tracking/start', query: [
            'carrier_code' => $carrierCode,
            'tracking_number' => $trackingNumber,
        ], retryable: false, context: 'start_tracking');
    }

    /**
     * Stop tracking a shipment and unsubscribe from webhook updates.
     */
    public function stopTracking(string $carrierCode, string $trackingNumber): array
    {
        $this->assertConfigured();

        return $this->request('post', '/v1/tracking/stop', query: [
            'carrier_code' => $carrierCode,
            'tracking_number' => $trackingNumber,
        ], retryable: false, context: 'stop_tracking');
    }

    /**
     * List carriers connected to the ShipEngine account.
     *
     * @return array<string, mixed> raw ShipEngine response
     */
    public function listCarriers(): array
    {
        $this->assertConfigured();

        return $this->request('get', '/v1/carriers', retryable: true, context: 'carriers');
    }

    /**
     * Validate a ship-to address (official /v1/addresses/validate contract).
     *
     * @param  array<string, mixed>  $address  address payload
     * @return array<int, array<string, mixed>> raw ShipEngine validation response
     */
    public function validateAddress(array $address): array
    {
        $this->assertConfigured();

        $response = $this->request('post', '/v1/addresses/validate', [$address], retryable: true, context: 'validate_address');

        return is_array($response) ? array_values($response) : [];
    }

    /**
     * List configured webhooks for the ShipEngine account.
     *
     * @return array<string, mixed> raw ShipEngine response
     */
    public function listWebhooks(): array
    {
        $this->assertConfigured();

        return $this->request('get', '/v1/environment/webhooks', retryable: true, context: 'list_webhooks');
    }

    /**
     * Register a webhook URL for a specific event.
     *
     * @param  string  $url  the fully-qualified webhook URL
     * @param  string  $event  the event to subscribe to (e.g. "track")
     * @return array<string, mixed> raw ShipEngine response
     */
    public function createWebhook(string $url, string $event): array
    {
        $this->assertConfigured();

        return $this->request('post', '/v1/environment/webhooks', [
            'url' => $url,
            'event' => $event,
        ], retryable: false, context: 'create_webhook');
    }

    /**
     * Remove a registered webhook by its ShipEngine ID.
     *
     * @return array<string, mixed> raw ShipEngine response
     */
    /**
     * Retrieve shipping rules from ShipStation / ShipEngine without /v1 prefix.
     * Follows official ShipStation documentation and portal rules.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getShippingRules(): array
    {
        return Cache::remember('shipstation_shipping_rules', 300, function () {
            try {
                if (! empty($this->apiKey) || ! empty($this->sandboxApiKey)) {
                    // Request ShipStation rules without /v1 prefix as per official documentation
                    $response = $this->request('get', '/shipping-rules', retryable: true, context: 'shipping_rules');
                    $rawRules = $response['shipping_rules'] ?? $response['rules'] ?? null;
                    if (! empty($rawRules) && is_array($rawRules)) {
                        return array_values(array_map(function ($rule) {
                            $code = (string) ($rule['rule_code'] ?? $rule['ruleCode'] ?? $rule['code'] ?? '');
                            $rawKey = ! empty($rule['key']) ? (string) $rule['key'] : ($code ?: (string) ($rule['name'] ?? ''));
                            $key = strtolower(trim($rawKey));
                            $key = str_replace([' ', '-'], '_', $key);
                            $key = rtrim($key, '_');

                            return [
                                'rule_code' => $code ?: strtoupper($key),
                                'rule_id' => $rule['rule_id'] ?? $rule['ruleId'] ?? $rule['id'] ?? null,
                                'rule_type' => $rule['rule_type'] ?? $rule['ruleType'] ?? 'Condition',
                                'key' => $key,
                                'name' => $rule['name'] ?? ucwords(str_replace('_', ' ', $key)),
                                'cost' => isset($rule['cost']) ? round((float) $rule['cost'], 2) : 0.00,
                                'estimated_delivery' => $rule['estimated_delivery'] ?? $rule['estimatedDelivery'] ?? 'Same day or next business day',
                                'description' => $rule['description'] ?? 'Automated conditional rule from ShipStation.',
                                'is_available' => isset($rule['is_available']) ? (bool) $rule['is_available'] : true,
                            ];
                        }, $rawRules));
                    }
                }
            } catch (\Throwable $e) {
                Log::info('ShipStation /shipping-rules dynamic fetch unavailable or unconfigured, utilizing portal rules: '.$e->getMessage());
            }

            return $this->getConfiguredShippingRules();
        });
    }

    /**
     * Shipping rules configured in ShipStation API Portal (https://api-portal.shipstation.com/shipping-rules).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfiguredShippingRules(): array
    {
        return [
            [
                'rule_code' => 'STANDARD_SHIPPING',
                'rule_id' => 'se-163',
                'rule_type' => 'Service Group',
                'key' => 'standard_shipping',
                'name' => 'Standard Shipping',
                'cost' => 0.00,
                'estimated_delivery' => '5-7 business days',
                'description' => 'Automated standard ground shipping service group from ShipStation.',
                'is_available' => true,
            ],
            [
                'rule_code' => 'EXPRESS_SHIPPING',
                'rule_id' => 'se-164',
                'rule_type' => 'Service Group',
                'key' => 'express_shipping',
                'name' => 'Express Shipping',
                'cost' => 0.00,
                'estimated_delivery' => '2-3 business days',
                'description' => 'Fast expedited priority delivery automated from ShipStation rules.',
                'is_available' => true,
            ],
            [
                'rule_code' => 'INTERNATIONAL_SHIPPING_',
                'rule_id' => 'se-162',
                'rule_type' => 'Service Group',
                'key' => 'international_shipping',
                'name' => 'International Shipping',
                'cost' => 0.00,
                'estimated_delivery' => '10-21 business days',
                'description' => 'Cross-border international delivery service group automated from ShipStation.',
                'is_available' => true,
            ],
            [
                'rule_code' => 'HEAVY_ITEM_PICKUP',
                'rule_id' => 'se-161',
                'rule_type' => 'Condition',
                'key' => 'heavy_item_pickup',
                'name' => 'Heavy Item Pickup',
                'cost' => 0.00,
                'estimated_delivery' => 'Same day or next business day',
                'description' => 'Automated conditional rule for heavy items pickup.',
                'is_available' => true,
            ],
            [
                'rule_code' => 'MULTI_ITEM_PICKUP',
                'rule_id' => 'se-160',
                'rule_type' => 'Condition',
                'key' => 'multi_item_pickup',
                'name' => 'Multi Item Pickup',
                'cost' => 0.00,
                'estimated_delivery' => 'Same day or next business day',
                'description' => 'Automated conditional rule for multi-item order pickup.',
                'is_available' => true,
            ],
        ];
    }

    // ─── Request core ───────────────────────────────────────────────

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
        bool $retryable = false,
        string $context = 'request',
    ): array {
        $attempt = 0;

        while (true) {
            $attempt++;
            $startedAt = microtime(true);

            try {
                $response = $this->send($method, $path, $body, $query);
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                $this->logInfo($context, $path, $response->status(), $durationMs, $attempt, null);

                return $this->parse($response);
            } catch (Throwable $e) {
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $status = $e instanceof ShipEngineApiException && $e->getCode() > 0 ? $e->getCode() : null;

                $this->logInfo($context, $path, $status, $durationMs, $attempt, $e->getMessage());

                if (! $this->shouldRetry($e, $retryable, $attempt)) {
                    if ($e instanceof ConnectionException) {
                        throw new ShipEngineApiException(
                            'ShipEngine connection failed: '.$e->getMessage(),
                            502,
                            $e
                        );
                    }

                    throw $e;
                }

                Log::channel('shipengine')->info('ShipEngine request failed, retrying', [
                    'operation' => $context,
                    'path' => $path,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                sleep($this->retryDelay($e, $attempt));
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>|null  $query
     */
    protected function send(string $method, string $path, ?array $body, ?array $query): Response
    {
        $client = Http::withHeaders([
            'API-Key' => $this->resolvedApiKey(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        $url = $this->baseUrl.$path;

        return match (strtoupper($method)) {
            'PUT' => $client->put($url, $body ?? []),
            'DELETE' => $client->delete($url, $query ?? []),
            'GET' => $client->get($url, $query ?? []),
            default => $client->post($url, $body ?? []),
        };
    }

    /**
     * Map an HTTP response to an array or a typed exception.
     *
     * @return array<string, mixed>
     */
    protected function parse(Response $response): array
    {
        $status = $response->status();
        $decoded = json_decode($response->body(), true);

        if ($status >= 200 && $status < 300) {
            if (! is_array($decoded)) {
                throw new ShipEngineApiException('ShipEngine returned an unparseable response.', 502);
            }

            return $decoded;
        }

        $message = $this->extractErrorMessage($decoded, $status);

        $exception = match (true) {
            in_array($status, [401, 403], true) => new ShipEngineAuthenticationException($message, $status, null, $decoded),
            $status === 429 => new ShipEngineRateLimitException($message, $status, null, $decoded),
            $status === 404 => new ShipEngineNotFoundException($message, $status, null, $decoded),
            in_array($status, [400, 409, 422], true) => new ShipEngineValidationException($message, $status, null, $decoded),
            default => new ShipEngineApiException($message, $status, null, $decoded),
        };

        if ($exception instanceof ShipEngineRateLimitException) {
            $retryAfter = $response->header('Retry-After');
            if ($retryAfter !== null && is_numeric($retryAfter)) {
                $exception->retryAfter = max(0, (int) $retryAfter);
            }
        }

        throw $exception;
    }

    /**
     * Retry policy: idempotent operations only. Never retry label purchase.
     */
    protected function shouldRetry(Throwable $e, bool $retryable, int $attempt): bool
    {
        if (! $retryable || $attempt >= $this->maxRetries) {
            return false;
        }

        if ($e instanceof ShipEngineRateLimitException || $e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof ShipEngineApiException && in_array($e->getCode(), [500, 503, 504], true);
    }

    /**
     * Backoff for a retry: Retry-After on 429, otherwise linear/exponential backoff.
     */
    protected function retryDelay(Throwable $e, int $attempt): int
    {
        if ($e instanceof ShipEngineRateLimitException && $e->retryAfter !== null) {
            return $e->retryAfter;
        }

        return $this->retryDelaySeconds * $attempt;
    }

    // ─── Errors & helpers ───────────────────────────────────────────

    protected function extractErrorMessage(mixed $decoded, int $status): string
    {
        if (is_array($decoded)) {
            if (isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '') {
                return $decoded['message'];
            }
            if (isset($decoded['error']) && is_string($decoded['error']) && $decoded['error'] !== '') {
                return $decoded['error'];
            }
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                $first = $decoded['errors'][0] ?? null;
                if (is_array($first) && isset($first['message']) && is_string($first['message'])) {
                    return $first['message'];
                }
            }
        }

        return "Shipping service request failed with HTTP {$status}.";
    }

    /**
     * The API key actually used: sandbox key in sandbox mode, production key otherwise.
     */
    public function resolvedApiKey(): string
    {
        return $this->sandboxMode ? $this->sandboxApiKey : $this->apiKey;
    }

    /**
     * Check if ShipEngine API is configured with a valid key.
     */
    public function isConfigured(): bool
    {
        $key = $this->resolvedApiKey();

        if (empty($key)) {
            return false;
        }

        $placeholders = [
            'your_api_key_here',
            'placeholder',
            'test_key',
            'sk_test_placeholder',
            'null',
        ];

        return ! in_array(strtolower(trim($key, '"\' ')), $placeholders, true);
    }

    public function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new ShipEngineAuthenticationException(
                'ShipEngine is not configured. Set '
                .($this->sandboxMode
                    ? 'SHIPENGINE_SANDBOX_API_KEY in your .env (sandbox mode).'
                    : 'SHIPENGINE_API_KEY in your .env.'),
                503
            );
        }
    }

    protected function logInfo(string $context, string $path, ?int $status, int $durationMs, int $attempt, ?string $error): void
    {
        Log::channel('shipengine')->debug('ShipEngine API call', [
            'operation' => $context,
            'path' => $path,
            'http_status' => $status,
            'duration_ms' => $durationMs,
            'attempt' => $attempt,
            'error' => $error,
        ]);
    }
}
