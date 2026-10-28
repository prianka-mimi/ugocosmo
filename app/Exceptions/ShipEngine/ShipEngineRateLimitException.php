<?php

namespace App\Exceptions\ShipEngine; /**
 * HTTP 429 — ShipEngine rate limit exceeded. Safe to retry with backoff
 * for idempotent operations.
 */
class ShipEngineRateLimitException extends ShipEngineApiException
{
    /** Seconds from the Retry-After response header, when provided. */
    public ?int $retryAfter = null;
}
