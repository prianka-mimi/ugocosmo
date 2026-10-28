<?php

namespace App\Exceptions\Etsy;

use Exception;
use Throwable;

/**
 * Base exception for all Etsy Open API v3 failures.
 */
class EtsyApiException extends Exception
{
    /** Raw payload returned by Etsy, if any. */
    public mixed $payload = null;

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, mixed $payload = null)
    {
        parent::__construct($message, $code, $previous);
        $this->payload = $payload;
    }
}
