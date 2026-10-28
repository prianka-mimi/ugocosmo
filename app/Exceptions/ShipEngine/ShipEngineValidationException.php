<?php

namespace App\Exceptions\ShipEngine;

/**
 * HTTP 400/409/422 — ShipEngine rejected the payload (invalid address,
 * unsupported service, conflicting state, ...). The message is the
 * sanitized ShipEngine error text.
 */
class ShipEngineValidationException extends ShipEngineApiException {}
