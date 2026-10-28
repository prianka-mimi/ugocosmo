<?php

namespace App\Exceptions\AliExpress;

/**
 * Thrown when the OAuth authorization/token flow fails
 * (invalid code, failed token exchange, invalid refresh token).
 */
class OAuthException extends AliExpressApiException {}
