<?php

namespace App\Services;

use App\Models\AliExpressApiLog;
use Illuminate\Support\Facades\Log;

/**
 * Persists one row per AliExpress request/response for admin visibility
 * (failed requests, API errors, latency).
 *
 * Logging must NEVER break the API call it records — every write is
 * wrapped so failures degrade to a Laravel log line only.
 */
class AliExpressApiLogger
{
    /** Max length of a stored response excerpt. */
    public const RESPONSE_PREVIEW_LIMIT = 2000;

    /**
     * Record a request/response pair.
     *
     * @param  array<string>  $paramKeys  parameter KEYS only — values are never persisted
     * @return AliExpressApiLog|null the persisted row (null when persistence failed)
     */
    public function record(
        string $method,
        string $endpoint,
        ?int $accountId,
        string $region,
        ?int $httpStatus,
        bool $success,
        ?string $errorCode,
        ?string $errorMessage,
        array $paramKeys,
        ?string $responsePreview,
        ?int $durationMs,
        bool $isSandbox = false,
        ?array $signatureVerification = null,
    ): ?AliExpressApiLog {
        try {
            return AliExpressApiLog::create([
                'aliexpress_account_id' => $accountId,
                'method' => $method,
                'endpoint' => $endpoint,
                'region' => $region,
                'http_status' => $httpStatus,
                'success' => $success,
                'is_sandbox' => $isSandbox,
                'error_code' => $errorCode !== null ? mb_substr($errorCode, 0, 64) : null,
                'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 2000) : null,
                'param_keys' => $paramKeys,
                'response_preview' => $success ? null : $this->truncate($responsePreview),
                'signature_verification' => $signatureVerification,
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $e) {
            // Logging must never interfere with the API call itself.
            Log::warning('AliExpressApiLogger: failed to persist API log', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function truncate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, self::RESPONSE_PREVIEW_LIMIT);
    }
}
