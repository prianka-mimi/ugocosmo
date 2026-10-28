<?php

namespace App\Exceptions\AliExpress;

/**
 * Thrown when AliExpress rate-limits the app.
 *
 * The API client retries transient failures automatically; this is only
 * thrown after the retry budget is exhausted.
 */
class RateLimitException extends AliExpressApiException {}
