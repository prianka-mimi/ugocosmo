<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency ledger for ShipEngine tracking webhooks (at-least-once
 * delivery). See the 2026_09_12_000004 migration for the rationale.
 */
class ShipEngineWebhookCall extends Model
{
    protected $table = 'shipengine_webhook_calls';

    protected $fillable = [
        'resource_token',
        'event_type',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
