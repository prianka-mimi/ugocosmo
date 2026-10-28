<?php

namespace App\Services;

/**
 * Backward-compatible alias / extension of ShipEngineClient.
 *
 * All low-level HTTP transport and error-mapping logic resides in
 * App\Services\ShipEngineClient.
 */
class ShipEngineApiClient extends ShipEngineClient
{
    // Inherits all methods and properties from ShipEngineClient.
}
