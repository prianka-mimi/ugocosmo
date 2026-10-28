<?php

namespace App\Exceptions\Etsy;

/**
 * Thrown when Etsy rate-limits the keystring (HTTP 429).
 *
 * The API client retries transient failures automatically; this is only
 * thrown after the retry budget is exhausted.
 */
class EtsyRateLimitException extends EtsyApiException {}
