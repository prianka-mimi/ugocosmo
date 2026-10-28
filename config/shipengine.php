<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ShipEngine / ShipStation API Configuration
    |--------------------------------------------------------------------------
    |
    | Credentials, environment endpoints, default package dimensions, and
    | webhook security settings for the ShipEngine / ShipStation v1 API.
    |
    */

    'api_key' => env('SHIPENGINE_API_KEY'),

    'base_url' => env('SHIPENGINE_BASE_URL', 'https://api.shipengine.com'),

    'timeout' => (int) env('SHIPENGINE_TIMEOUT', 30),

    'connect_timeout' => (int) env('SHIPENGINE_CONNECT_TIMEOUT', 5),

    'max_retries' => (int) env('SHIPENGINE_MAX_RETRIES', 3),

    'retry_delay_seconds' => (int) env('SHIPENGINE_RETRY_DELAY', 2),

    'sandbox_mode' => (bool) env('SHIPENGINE_SANDBOX_MODE', false),

    'sandbox_api_key' => env('SHIPENGINE_SANDBOX_API_KEY'),

    'mock_mode' => (bool) env('SHIPENGINE_MOCK_MODE', false),

    'carrier_ids' => array_filter(array_map('trim', explode(',', (string) env('SHIPENGINE_CARRIER_IDS', 'se-360748,se-360749,se-360750,se-360751,se-360752,se-399629,se-399630')))),

    // Default weight fallback when a product lacks an explicit weight
    'default_weight_value' => (float) env('SHIPENGINE_DEFAULT_WEIGHT_VALUE', 1.0),

    // Default weight unit: pound | ounce | gram | kilogram
    'default_weight_unit' => env('SHIPENGINE_DEFAULT_WEIGHT_UNIT', 'pound'),

    // Backward-compatible alias for existing service usage
    'weight_unit' => env('SHIPENGINE_WEIGHT_UNIT', env('SHIPENGINE_DEFAULT_WEIGHT_UNIT', 'pound')),

    // Default package dimensions fallback when a product lacks package_dimensions
    'default_dimensions' => [
        'length' => (float) env('SHIPENGINE_DEFAULT_LENGTH', 10.0),
        'width' => (float) env('SHIPENGINE_DEFAULT_WIDTH', 5.0),
        'height' => (float) env('SHIPENGINE_DEFAULT_HEIGHT', 5.0),
        'unit' => env('SHIPENGINE_DEFAULT_DIMENSION_UNIT', 'inch'),
    ],

    // Ship-from origin address snapshot
    'ship_from' => (function () {
        $json = env('SHIPENGINE_SHIP_FROM');
        if (! $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    })(),

    'tracking_sync_minutes' => (int) env('SHIPENGINE_TRACKING_SYNC_MINUTES', 180),

    // Webhook authentication (shared secret fallback for dev/local)
    'webhook_secret' => env('SHIPENGINE_WEBHOOK_SECRET'),

    'webhook_header' => env('SHIPENGINE_WEBHOOK_HEADER', 'X-ShipEngine-Signature'),

    // RSA-SHA256 signature verification via JWKS (official ShipStation contract)
    'jwks_url' => env('SHIPSTATION_JWKS_URL', 'https://api.shipengine.com/jwks'),

    'webhook_timestamp_tolerance' => (int) env('SHIPENGINE_WEBHOOK_TIMESTAMP_TOLERANCE', 300),

    'jwks_cache_ttl' => (int) env('SHIPENGINE_JWKS_CACHE_TTL', 86400),

];
