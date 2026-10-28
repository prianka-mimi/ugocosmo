<?php

namespace App\Exceptions\AliExpress;

use Exception;
use Throwable;

/**
 * Base exception for all AliExpress Open Platform failures.
 */
class AliExpressApiException extends Exception
{
    /** Raw payload returned by AliExpress (error_response body), if any. */
    public mixed $payload = null;

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, mixed $payload = null)
    {
        parent::__construct($message, $code, $previous);
        $this->payload = $payload;
    }
}
