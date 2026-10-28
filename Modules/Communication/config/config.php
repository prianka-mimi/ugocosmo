<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Call Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Define the calling engine used for different call types.
    | You can use 'livekit', 'agora', 'zego', 'p2p', or 'none'.
    | The selection depends on the type of call (audio vs video) and
    | the scale (one-to-one vs group).
    |
    */
    'call_engine' => [
        'audio_one_to_one' => env('CALL_ENGINE_AUDIO_ONE_TO_ONE', 'livekit'),
        'audio_group' => env('CALL_ENGINE_AUDIO_GROUP', 'livekit'),
        'video_one_to_one' => env('CALL_ENGINE_VIDEO_ONE_TO_ONE', 'livekit'),
        'video_group' => env('CALL_ENGINE_VIDEO_GROUP', 'livekit'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agora Credentials
    |--------------------------------------------------------------------------
    |
    | If you are using 'agora' as an engine, provide your App ID and
    | Certificate (if using tokens) below.
    |
    */
    'agora' => [
        'app_id' => env('AGORA_APP_ID', ''),
        'app_certificate' => env('AGORA_APP_CERTIFICATE', ''),
        'token_ttl' => env('AGORA_TOKEN_TTL', 3600),
        'customer_id' => env('AGORA_CUSTOMER_ID', ''),
        'customer_secret' => env('AGORA_CUSTOMER_SECRET', ''),
        'kick_rule_seconds' => env('AGORA_KICK_RULE_SECONDS', 120),
        'webhook_secret' => env('AGORA_WEBHOOK_SECRET', ''),
    ],

    'livekit' => [
        'url' => env('LIVEKIT_URL', ''),
        'api_key' => env('LIVEKIT_API_KEY', ''),
        'api_secret' => env('LIVEKIT_API_SECRET', ''),
        'token_ttl' => env('LIVEKIT_TOKEN_TTL', 3600),
        'webhook_token' => env('LIVEKIT_WEBHOOK_TOKEN', ''),
        'kick_rule_seconds' => env('LIVEKIT_KICK_RULE_SECONDS', 120),
    ],

    'message_db_driver' => env('MESSAGE_DB_DRIVER', 'mariadb'), // Options: 'mariadb', 'mongodb'
    'receipt_tracking' => env('RECEIPT_TRACKING', 'detailed'), // Options: 'none', 'standard', 'detailed'
    'broadcast_driver' => env('COMMUNICATION_BROADCAST_DRIVER', 'reverb'), // Options: 'reverb', 'pusher'
    'broadcast_auth_middleware' => env('COMMUNICATION_BROADCAST_AUTH_MIDDLEWARE', 'auth:api'),
    'api_auth_middleware' => env('COMMUNICATION_API_AUTH_MIDDLEWARE', 'auth:api'),
    'call_ring_timeout_seconds_one_to_one' => env('COMMUNICATION_CALL_RING_TIMEOUT_SECONDS_ONE_TO_ONE', 60),
    'call_ring_timeout_seconds_group' => env('COMMUNICATION_CALL_RING_TIMEOUT_SECONDS_GROUP', 60),
    'online_ttl_minutes' => env('COMMUNICATION_ONLINE_TTL_MINUTES', 5),
];
