<?php

namespace App\Exceptions\AliExpress;

/**
 * Thrown when the AliExpress session/access token is invalid or expired.
 *
 * Callers should surface this to the seller ("reconnect your AliExpress
 * account") instead of retrying the failed request blindly.
 */
class TokenExpiredException extends AliExpressApiException {}
