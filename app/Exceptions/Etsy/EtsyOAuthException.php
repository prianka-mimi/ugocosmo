<?php

namespace App\Exceptions\Etsy;

/**
 * OAuth-specific failure (state validation, code exchange, refresh).
 * Mirrors App\Exceptions\AliExpress\OAuthException.
 */
class EtsyOAuthException extends EtsyApiException {}
