<?php

namespace App\Exceptions\AliExpress;

/**
 * Thrown when ALIEXPRESS_SANDBOX_MODE is enabled: the request was signed
 * and captured for signature verification, but intentionally NOT sent to
 * AliExpress. The full verification payload is available on ->payload.
 */
class SandboxModeException extends AliExpressApiException
{
    public const CODE = 503;

    public function __construct(array $verification, ?int $apiLogId = null, string $message = '')
    {
        parent::__construct(
            $message ?: 'AliExpress sandbox mode is enabled — request captured for signature '
                .'verification and NOT sent to AliExpress. Inspect aliexpress_api_logs '
                .'(is_sandbox=1)'.($apiLogId !== null ? " (row id {$apiLogId})" : '')
                .' or storage/logs/aliexpress-sandbox.log.',
            self::CODE,
            null,
            $verification
        );
    }
}
