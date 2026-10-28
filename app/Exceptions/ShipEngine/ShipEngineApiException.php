<?php

namespace App\Exceptions\ShipEngine;

use Exception;
use Throwable;

/**
 * Base exception for all ShipEngine / ShipStation API failures.
 *
 * The HTTP status is kept as the exception code so controllers can map it
 * to a response status. Raw payloads are attached for server-side logging
 * only — they are never returned to clients.
 */
class ShipEngineApiException extends Exception
{
    /** Raw ShipEngine error payload, if any (server-side logging only). */
    public mixed $payload = null;

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, mixed $payload = null)
    {
        parent::__construct($message, $code, $previous);
        $this->payload = $payload;
    }
}
