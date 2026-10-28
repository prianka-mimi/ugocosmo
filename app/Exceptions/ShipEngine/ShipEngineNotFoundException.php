<?php

namespace App\Exceptions\ShipEngine;

/**
 * HTTP 404 — ShipEngine could not find the referenced resource (label,
 * shipment, rate, carrier).
 */
class ShipEngineNotFoundException extends ShipEngineApiException {}
