<?php

namespace App\Exceptions\ShipEngine;

/**
 * HTTP 401/403 — the configured API key is invalid, missing, or lacks
 * permission. This is a server configuration problem, never client input.
 */
class ShipEngineAuthenticationException extends ShipEngineApiException {}
