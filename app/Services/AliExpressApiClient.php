<?php

namespace App\Services;

use App\Exceptions\AliExpress\AliExpressApiException;
use App\Exceptions\AliExpress\OAuthException;
use App\Exceptions\AliExpress\RateLimitException;
use App\Exceptions\AliExpress\SandboxModeException;
use App\Exceptions\AliExpress\TokenExpiredException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Low-level HTTP client for the AliExpress Open Platform.
 *
 * Handles:
 *  - HMAC-SHA256 request signing (Alibaba "top" style)
 *  - Synchronous API calls (POST /sync with method + param_json)
 *  - OAuth token create/refresh calls
 *  - Error-response mapping to typed exceptions
 *  - Transparent retry for rate limits / 5xx / network failures
 *
 * NOTE: exact API method names and response shapes vary by app tier —
 * they are centralized in config('services.aliexpress.methods').
 */
class AliExpressApiClient
{
    protected string $appKey;

    protected string $appSecret;

    protected string $apiVersion;

    protected int $timeout;

    protected int $maxRetries;

    protected int $retryDelaySeconds;

    protected array $baseUris;

    protected array $methods;

    protected array $oauthMethods;

    protected string $syncPath;

    protected string $restPath;

    /** Sandbox/verification mode — requests are signed + captured, never sent. */
    protected bool $sandboxMode = false;

    /** Optional secret override for sandbox verification runs. */
    protected ?string $sandboxSecret = null;

    /** Placeholder replacing the seller access token in sandbox captures. */
    public const SANDBOX_SESSION_PLACEHOLDER = 'SANDBOX_SESSION_TOKEN';

    /** Placeholder replacing the OAuth authorization code in sandbox captures. */
    public const SANDBOX_CODE_PLACEHOLDER = 'SANDBOX_AUTH_CODE';

    /** Placeholder replacing the refresh token in sandbox captures. */
    public const SANDBOX_TOKEN_PLACEHOLDER = 'SANDBOX_REFRESH_TOKEN';

    /** Lazily resolved — kept nullable so `new AliExpressApiClient()` stays usable in tests. */
    protected ?AliExpressApiLogger $apiLogger = null;

    /** Lazily resolved — kept nullable so `new AliExpressApiClient()` stays usable in tests. */
    protected ?AliExpressSignatureService $signatureService = null;

    public function __construct()
    {
        $config = config('services.aliexpress') ?? [];

        $this->appKey = (string) ($config['app_key'] ?? '');
        $this->appSecret = (string) ($config['app_secret'] ?? '');
        $this->sandboxMode = (bool) ($config['sandbox_mode'] ?? false);
        $this->sandboxSecret = (string) ($config['sandbox_secret'] ?? '') ?: null;
        $this->apiVersion = (string) ($config['api_version'] ?? '2.0');
        $this->timeout = (int) ($config['request_timeout'] ?? 30);
        $this->maxRetries = (int) ($config['max_import_retries'] ?? 3);
        $this->retryDelaySeconds = (int) ($config['retry_delay_seconds'] ?? 2);
        $this->baseUris = (array) ($config['base_uris'] ?? []);
        $this->methods = (array) ($config['methods'] ?? []);
        $this->oauthMethods = (array) ($config['oauth_methods'] ?? []);
        $this->syncPath = (string) ($config['sync_path'] ?? '/sync');
        $this->restPath = (string) ($config['rest_path'] ?? '/rest');
    }

    // ─── Public API ────────────────────────────────────────────────

    /**
     * Invoke a synchronous Open Platform API method.
     *
     * @param  array<string, mixed>  $params  private parameters (sent as param_json)
     * @param  string|null  $session  seller access token (required for merchant APIs)
     * @return array the successful <method>_response payload
     *
     * @throws TokenExpiredException
     * @throws RateLimitException
     * @throws AliExpressApiException
     */
    public function call(string $method, array $params = [], ?string $session = null, ?string $region = null, ?int $accountId = null, bool $flatParams = false, ?string $endpoint = null): array
    {
        $this->assertConfigured();

        // System, OAuth, and AliExpress TOP RPC calls (aliexpress.ds.*) all route to /sync
        $endpoint = $endpoint ?? $this->syncPath;

        $region = $region ?: (string) config('services.aliexpress.region', 'sg');

        if ($this->sandboxEnabled()) {
            return $this->captureSandbox(
                'call',
                $method,
                $endpoint,
                $this->buildPublicParams($method, $params, self::SANDBOX_SESSION_PLACEHOLDER, null, $flatParams),
                $accountId,
                $region
            );
        }

        $publicParams = $this->buildPublicParams($method, $params, $session, null, $flatParams);

        return $this->withRetry(function () use ($method, $params, $session, $region, $accountId, $endpoint, $publicParams) {
            $startedAt = microtime(true);

            Log::debug('AliExpressApiClient request', [
                'method' => $method,
                'region' => $region,
                'has_session' => ! empty($session),
                'param_keys' => array_keys($params),
            ]);

            $response = Http::asForm()
                ->timeout($this->timeout)
                ->post($this->baseUrl($region).$endpoint, $publicParams);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::debug('AliExpressApiClient response', [
                'method' => $method,
                'http_status' => $response->status(),
                'body_preview' => mb_substr($response->body(), 0, 500),
            ]);

            try {
                $parsed = $this->parseResponse($response->body(), $method);

                $this->logger()->record(
                    $method,
                    $endpoint,
                    $accountId,
                    $region,
                    $response->status(),
                    true,
                    null,
                    null,
                    array_keys($params),
                    null,
                    $durationMs
                );

                return $parsed;
            } catch (AliExpressApiException $e) {
                $this->logger()->record(
                    $method,
                    $endpoint,
                    $accountId,
                    $region,
                    $response->status(),
                    false,
                    $e->getCode() > 0 ? (string) $e->getCode() : 'api_error',
                    $e->getMessage(),
                    array_keys($params),
                    $this->responsePreview($response->body()),
                    $durationMs
                );

                throw $e;
            }
        }, $method, [
            'method' => $method,
            'endpoint' => $endpoint,
            'account_id' => $accountId,
            'region' => $region,
            'param_keys' => array_keys($params),
        ]);
    }

    /**
     * Execute multiple Open Platform API calls concurrently via Http::pool.
     *
     * @param  array<string, array{method: string, params: array, flatParams?: bool, endpoint?: string}>  $calls
     * @return array<string, array> parsed response payloads keyed by the input keys
     */
    public function pool(array $calls, ?string $session = null, ?string $region = null, ?int $accountId = null): array
    {
        if (empty($calls)) {
            return [];
        }

        $this->assertConfigured();

        $region = $region ?: (string) config('services.aliexpress.region', 'sg');
        $baseUrl = $this->baseUrl($region);
        $defaultEndpoint = $this->syncPath;

        $prepared = [];
        foreach ($calls as $key => $call) {
            $method = $call['method'];
            $params = $call['params'] ?? [];
            $flat = $call['flatParams'] ?? true;
            $endpoint = $call['endpoint'] ?? $defaultEndpoint;

            $publicParams = $this->buildPublicParams($method, $params, $session, null, $flat);
            $prepared[(string) $key] = [
                'method' => $method,
                'url' => $baseUrl.$endpoint,
                'params' => $publicParams,
            ];
        }

        $responses = Http::pool(function (Pool $pool) use ($prepared) {
            $poolCalls = [];
            foreach ($prepared as $key => $item) {
                $poolCalls[] = $pool->as((string) $key)
                    ->asForm()
                    ->timeout($this->timeout)
                    ->post($item['url'], $item['params']);
            }

            return $poolCalls;
        });

        $results = [];
        foreach ($responses as $key => $response) {
            $keyStr = (string) $key;
            $method = $prepared[$keyStr]['method'] ?? '';

            if ($response instanceof Response && $response->successful()) {
                try {
                    $results[$keyStr] = $this->parseResponse($response->body(), $method);
                } catch (\Throwable $e) {
                    Log::warning("AliExpressApiClient pool response parse failed for [{$keyStr}]: ".$e->getMessage());
                }
            } elseif ($response instanceof \Throwable) {
                Log::warning("AliExpressApiClient pool request failed for [{$keyStr}]: ".$response->getMessage());
            }
        }

        return $results;
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array raw token response (access_token, refresh_token, ...)
     *
     * @throws OAuthException
     */
    public function tokenCreate(string $code, string $redirectUri, ?string $region = null, ?int $accountId = null): array
    {
        $this->assertConfigured();

        // OAuth token methods are REST-style: invoked through the /sync
        // gateway with `method=/auth/token/create` (the method name is also
        // part of the signed string). See config/services.php → oauth_methods.
        $method = $this->oauthMethod('token_create');
        $endpoint = $this->syncPath;
        $region = $region ?: (string) config('services.aliexpress.region', 'sg');

        if ($this->sandboxEnabled()) {
            return $this->captureSandbox(
                'token_create',
                $method,
                $endpoint,
                $this->signedParams([
                    'grant_type' => 'authorization_code',
                    'code' => self::SANDBOX_CODE_PLACEHOLDER,
                    'redirect_uri' => $redirectUri,
                ], $method),
                $accountId,
                $region
            );
        }

        $params = $this->signedParams([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ], $method);

        return $this->withRetry(function () use ($params, $region, $accountId, $endpoint, $method) {
            $startedAt = microtime(true);

            $response = Http::asForm()
                ->timeout($this->timeout)
                ->post($this->baseUrl($region).$endpoint, $params);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            try {
                $parsed = $this->parseOAuthResponse($response->body());

                $this->logger()->record(
                    $method,
                    $endpoint,
                    $accountId,
                    $region,
                    $response->status(),
                    true,
                    null,
                    null,
                    array_keys($params),
                    null,
                    $durationMs
                );

                return $parsed;
            } catch (AliExpressApiException $e) {
                $this->logger()->record(
                    $method,
                    $endpoint,
                    $accountId,
                    $region,
                    $response->status(),
                    false,
                    $e->getCode() > 0 ? (string) $e->getCode() : 'oauth_error',
                    $e->getMessage(),
                    array_keys($params),
                    $this->responsePreview($response->body()),
                    $durationMs
                );

                throw $e;
            }
        }, 'token_create', [
            'method' => 'token_create',
            'endpoint' => $endpoint,
            'account_id' => $accountId,
            'region' => $region,
            'param_keys' => ['method', 'grant_type', 'code', 'redirect_uri', 'app_key', 'timestamp', 'sign_method', 'format', 'simplify', 'sign'],
        ]);
    }

    /**
     * Refresh an expired access token.
     *
     * @return array raw token response
     *
     * @throws OAuthException
     */
    public function tokenRefresh(string $refreshToken, ?string $region = null, ?int $accountId = null): array
    {
        $this->assertConfigured();

        // Same REST-style gateway pattern as tokenCreate (method /auth/token/refresh).
        $method = $this->oauthMethod('token_refresh');
        $endpoint = $this->syncPath;
        $region = $region ?: (string) config('services.aliexpress.region', 'sg');

        if ($this->sandboxEnabled()) {
            return $this->captureSandbox(
                'token_refresh',
                $method,
                $endpoint,
                $this->signedParams([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => self::SANDBOX_TOKEN_PLACEHOLDER,
                ], $method),
                $accountId,
                $region
            );
        }

        $params = $this->signedParams([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], $method);

        return $this->withRetry(function () use ($params, $region, $accountId, $endpoint, $method) {
            $startedAt = microtime(true);

            $response = Http::asForm()
                ->timeout($this->timeout)
                ->post($this->baseUrl($region).$endpoint, $params);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            try {
                $parsed = $this->parseOAuthResponse($response->body());

                $this->logger()->record(
                    $method,
                    $endpoint,
                    $accountId,
                    $region,
                    $response->status(),
                    true,
                    null,
                    null,
                    array_keys($params),
                    null,
                    $durationMs
                );

                return $parsed;
            } catch (AliExpressApiException $e) {
                $this->logger()->record(
                    $method,
                    $endpoint,
                    $accountId,
                    $region,
                    $response->status(),
                    false,
                    $e->getCode() > 0 ? (string) $e->getCode() : 'oauth_error',
                    $e->getMessage(),
                    array_keys($params),
                    $this->responsePreview($response->body()),
                    $durationMs
                );

                throw $e;
            }
        }, 'token_refresh', [
            'method' => 'token_refresh',
            'endpoint' => $endpoint,
            'account_id' => $accountId,
            'region' => $region,
            'param_keys' => ['method', 'grant_type', 'refresh_token', 'app_key', 'timestamp', 'sign_method', 'format', 'simplify', 'sign'],
        ]);
    }

    // ─── Signing ───────────────────────────────────────────────────

    /**
     * Whether sandbox/verification mode is enabled.
     *
     * When enabled, requests are signed and captured for signature
     * verification but NEVER sent to AliExpress (SandboxModeException).
     */
    public function sandboxEnabled(): bool
    {
        return $this->sandboxMode;
    }

    /**
     * Build the signed public parameter set for an API call.
     * Public so the offline verifier (aliexpress:verify-signature) can
     * reproduce the exact request payload without any network traffic.
     */
    public function buildPublicParams(string $method, array $params, ?string $session, ?string $timestamp = null, bool $flatParams = false): array
    {
        $publicParams = [
            'method' => $method,
            'app_key' => $this->appKey,
            'timestamp' => $timestamp ?? (string) (int) round(microtime(true) * 1000),
            'format' => 'json',
            'v' => $this->apiVersion,
            'sign_method' => 'sha256',
            'simplify' => 'true',
        ];

        if (! empty($params)) {
            if ($flatParams) {
                // Some DS APIs (e.g. feed.itemids.get) reject param_json and
                // expect business params as flat form fields alongside system params.
                $publicParams = array_merge($publicParams, $params);
            } else {
                $publicParams['param_json'] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if ($session) {
            $publicParams['session'] = $session;
        }

        $signMethod = str_starts_with($method, '/') ? $method : null;
        $publicParams['sign'] = $this->sign($publicParams, null, $signMethod);

        return $publicParams;
    }

    /**
     * Build signed parameters for an OAuth token call (REST-style: the
     * gateway requires `method` plus the standard system params, and the
     * REST method name is part of the signed string).
     */
    protected function signedParams(array $params, string $method): array
    {
        $params = array_merge([
            'method' => $method,
            'app_key' => $this->appKey,
            'timestamp' => (string) (int) round(microtime(true) * 1000),
            'sign_method' => 'sha256',
            'format' => 'json',
            'simplify' => 'true',
        ], $params);

        $signMethod = str_starts_with($method, '/') ? null : $method;
        $params['sign'] = $this->sign($params, null, $signMethod);

        return $params;
    }

    /**
     * AliExpress signature: sort all params (excluding sign) by key,
     * concatenate each as key+value with no separators, then
     * HMAC-SHA256 with the app secret (uppercase hex). For REST-style
     * methods (name contains "/") the method name is prepended first.
     *
     * Delegates to AliExpressSignatureService (single source of truth).
     */
    public function sign(array $params, ?string $secret = null, ?string $method = null): string
    {
        return $this->signatureService()->sign($params, $secret ?? $this->appSecret, $method);
    }

    // ─── Parsing & errors ──────────────────────────────────────────

    protected function parseResponse(string $body, string $method): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new AliExpressApiException(
                'AliExpress returned an unparseable response.',
                502,
                null,
                ['body' => $body]
            );
        }

        if (isset($decoded['error_response'])) {
            throw $this->mapApiError($decoded['error_response']);
        }

        // Success envelope: <method>_response
        $envelopeKey = str_replace('.', '_', $method).'_response';
        $payload = $decoded[$envelopeKey] ?? $decoded;

        return is_array($payload) ? $payload : ['result' => $payload];
    }

    protected function parseOAuthResponse(string $body): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new OAuthException(
                'AliExpress returned an unparseable OAuth response.',
                502,
                null,
                ['body' => $body]
            );
        }

        if (isset($decoded['error_response'])) {
            $error = $decoded['error_response'];
            throw new OAuthException(
                (string) ($error['msg'] ?? $error['sub_msg'] ?? 'AliExpress OAuth failed.'),
                (int) ($error['code'] ?? 400),
                null,
                $error
            );
        }

        // REST-style OAuth errors come back as a top-level code/message
        // pair; a code of "0" means success (official token sample).
        if (isset($decoded['code']) && (string) $decoded['code'] !== '0') {
            throw new OAuthException(
                (string) ($decoded['message'] ?? $decoded['sub_msg'] ?? 'AliExpress OAuth failed.'),
                (int) ($decoded['code'] ?: 400),
                null,
                $decoded
            );
        }

        return $decoded;
    }

    protected function mapApiError(array $error): AliExpressApiException
    {
        $code = (int) ($error['code'] ?? 0);
        $subCode = mb_strtolower((string) ($error['sub_code'] ?? ''));
        $message = (string) ($error['sub_msg'] ?? $error['msg'] ?? 'AliExpress API error.');

        // Unavailable/unapproved API method (tier- or app-type dependent).
        // Surface an actionable message instead of a bare platform error so
        // operators know to check services.aliexpress.methods against the
        // app's approved API list in the Open Platform console.
        if (str_contains($subCode, 'method')
            || str_contains($subCode, 'api-package')
            || str_contains(mb_strtolower($message), 'missing method')
            || str_contains(mb_strtolower($message), 'invalid method')
            || str_contains(mb_strtolower($message), 'not exist')
            || str_contains(mb_strtolower($message), 'api path is invalid')
            || str_contains(mb_strtolower($message), 'specified api path')
        ) {
            return new AliExpressApiException(
                'AliExpress rejected the API method (it may be unavailable for this app type/tier). '
                    .'Verify services.aliexpress.methods against the approved API list in the Open Platform console. '
                    .'Platform message: '.$message,
                $code !== 0 ? $code : 501,
                null,
                $error
            );
        }

        if ($code === 429
            || str_contains($subCode, 'rate.limit')
            || str_contains($subCode, 'quantity-exceeded')
            || str_contains($subCode, 'concurrency')
        ) {
            return new RateLimitException($message, $code, null, $error);
        }

        // Session/token errors are scoped to the machine-readable sub_code
        // (e.g. isv.invalid-session) to avoid misclassifying human messages.
        if ($code === 401
            || $code === 40003
            || str_contains($subCode, 'session')
            || str_contains($subCode, 'token')
        ) {
            return new TokenExpiredException($message, $code, null, $error);
        }

        return new AliExpressApiException($message, $code, null, $error);
    }

    // ─── Retry ─────────────────────────────────────────────────────

    protected function withRetry(callable $callable, string $context, array $logContext = []): array
    {
        $attempt = 1;

        while (true) {
            try {
                return $callable();
            } catch (RateLimitException|ConnectionException $e) {
                if ($attempt >= $this->maxRetries) {
                    Log::warning('AliExpressApiClient: retry budget exhausted', [
                        'context' => $context,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    if ($e instanceof ConnectionException) {
                        $this->logger()->record(
                            $logContext['method'] ?? $context,
                            $logContext['endpoint'] ?? '',
                            $logContext['account_id'] ?? null,
                            $logContext['region'] ?? (string) config('services.aliexpress.region', 'sg'),
                            null,
                            false,
                            'connection',
                            $e->getMessage(),
                            $logContext['param_keys'] ?? [],
                            null,
                            null
                        );
                    }

                    throw $e instanceof RateLimitException
                        ? $e
                        : new AliExpressApiException('AliExpress connection failed: '.$e->getMessage(), 502, $e);
                }

                $delay = $this->retryDelaySeconds * $attempt;
                Log::info('AliExpressApiClient: transient failure, retrying', [
                    'context' => $context,
                    'attempt' => $attempt,
                    'delay' => $delay,
                    'error' => $e->getMessage(),
                ]);
                sleep($delay);
                $attempt++;
            } catch (AliExpressApiException $e) {
                // Permanent client-side errors (invalid session, invalid
                // parameters, unauthorized app, business rejections) are
                // NEVER retried — a retry would repeat the same failure and
                // burn the rate-limit budget. Only 5xx is transient.
                if ($e->getCode() < 500) {
                    throw $e;
                }

                if ($attempt >= $this->maxRetries) {
                    Log::warning('AliExpressApiClient: retry budget exhausted for 5xx', [
                        'context' => $context,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    $this->logger()->record(
                        $logContext['method'] ?? $context,
                        $logContext['endpoint'] ?? '',
                        $logContext['account_id'] ?? null,
                        $logContext['region'] ?? (string) config('services.aliexpress.region', 'sg'),
                        null,
                        false,
                        (string) ($e->getCode() > 0 ? $e->getCode() : 'api_error'),
                        $e->getMessage(),
                        $logContext['param_keys'] ?? [],
                        null,
                        null
                    );

                    throw $e;
                }

                $delay = $this->retryDelaySeconds * $attempt;
                Log::info('AliExpressApiClient: 5xx transient failure, retrying', [
                    'context' => $context,
                    'attempt' => $attempt,
                    'delay' => $delay,
                    'error' => $e->getMessage(),
                ]);
                sleep($delay);
                $attempt++;
            }
        }
    }

    // ─── Sandbox / verification mode ───────────────────────────────

    /**
     * Sign + capture a request instead of sending it.
     *
     * The exact string-to-sign and computed signature are persisted to
     * aliexpress_api_logs (is_sandbox=1) and the aliexpress-sandbox log
     * channel. Sensitive values (session/code/refresh_token) have already
     * been replaced with placeholders by the callers. Always throws.
     */
    protected function captureSandbox(string $context, string $method, string $endpoint, array $signedParams, ?int $accountId, string $region): never
    {
        $verification = $this->signatureService()->verificationPayload($signedParams, $this->resolveSandboxSecret(), null, $method);

        $log = $this->logger()->record(
            $method,
            $endpoint,
            $accountId,
            $region,
            null,               // no HTTP request was made
            true,               // capture succeeded
            null,
            null,
            array_keys($signedParams),
            null,
            null,
            true,               // is_sandbox
            $verification
        );

        Log::channel('aliexpress-sandbox')->info('AliExpress sandbox: signed request captured — NOT sent', [
            'context' => $context,
            'method' => $method,
            'endpoint' => $endpoint,
            'region' => $region,
            'api_log_id' => $log?->id,
            'verification' => $verification,
        ]);

        throw new SandboxModeException($verification, $log?->id);
    }

    /**
     * The secret used for sandbox captures: explicit sandbox secret
     * override, else the production app secret.
     */
    protected function resolveSandboxSecret(): string
    {
        return $this->sandboxSecret ?? $this->appSecret;
    }

    // ─── Helpers ───────────────────────────────────────────────────

    protected function signatureService(): AliExpressSignatureService
    {
        return $this->signatureService ??= app(AliExpressSignatureService::class);
    }

    protected function logger(): AliExpressApiLogger
    {
        return $this->apiLogger ??= app(AliExpressApiLogger::class);
    }

    /**
     * Response excerpt stored on failed calls only.
     */
    protected function responsePreview(string $body): ?string
    {
        $body = trim($body);

        return $body === '' ? null : mb_substr($body, 0, AliExpressApiLogger::RESPONSE_PREVIEW_LIMIT);
    }

    protected function baseUrl(?string $region): string
    {
        $region = $region ?: config('services.aliexpress.region', 'sg');

        return $this->baseUris[$region] ?? $this->baseUris['sg'] ?? 'https://api-sg.aliexpress.com';
    }

    protected function oauthMethod(string $key): string
    {
        return (string) ($this->oauthMethods[$key] ?? "/auth/{$key}");
    }

    protected function assertConfigured(): void
    {
        // Sandbox mode may run with only a throwaway sandbox secret
        // (no production app secret on the machine). The app key is
        // always required to build the signed request.
        $hasSecret = $this->appSecret !== '' || ($this->sandboxMode && $this->sandboxSecret !== null);

        if ($this->appKey === '' || ! $hasSecret) {
            throw new AliExpressApiException(
                'AliExpress is not configured. Set ALIEXPRESS_APP_KEY and '
                    .($this->sandboxMode
                        ? 'ALIEXPRESS_APP_SECRET or ALIEXPRESS_SANDBOX_SECRET in your .env.'
                        : 'ALIEXPRESS_APP_SECRET in your .env.'),
                500
            );
        }
    }
}
