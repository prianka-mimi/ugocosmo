<?php

namespace App\Services;

/**
 * Backward-compatible alias / extension of ShipEngineService.
 *
 * All business logic, state transitions, and database orchestration
 * reside in App\Services\ShipEngineService.
 */
class ShipmentService extends ShipEngineService
{
    // Inherits all business logic and orchestration from ShipEngineService.
}
