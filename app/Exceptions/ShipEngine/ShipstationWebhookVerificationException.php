<?php

namespace App\Exceptions\ShipEngine;

use Exception;

/**
 * Thrown when ShipStation webhook signature verification fails.
 *
 * Carries a machine-readable status code that maps to the HTTP response
 * the controller should return, per the official webhook contract:
 *
 *  - MISSING_HEADERS  → 404  (provider misconfiguration / not a ShipStation webhook)
 *  - INVALID_TIMESTAMP → 400 (stale or future timestamp — replay attack)
 *  - INVALID_SIGNATURE → 401 (signature does not match the provider's key)
 *
 * The human message is sanitized — never contains the raw signature, key
 * material, or the API key.
 */
class ShipstationWebhookVerificationException extends Exception
{
    public const MISSING_HEADERS = 404;

    public const INVALID_TIMESTAMP = 400;

    public const INVALID_SIGNATURE = 401;

    public function __construct(string $message, public int $verificationStatus)
    {
        parent::__construct($message, $verificationStatus);
    }
}
