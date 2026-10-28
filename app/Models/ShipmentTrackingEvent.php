<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One carrier tracking event for a shipment (from the ShipEngine tracking
 * webhook or the manual tracking refresh).
 *
 * Idempotency: unique on (shipment_id, external_event_id). Use
 * firstOrCreateByExternal() — it also deduplicates events without an
 * external id by the full tuple, so webhook retries and poll/webhook
 * overlap can never create duplicate rows.
 */
class ShipmentTrackingEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id',
        'external_event_id',
        'status',
        'status_code',
        'description',
        'city_locality',
        'state_province',
        'postal_code',
        'country_code',
        'event_time',
        'raw_payload',
        'processed_at',
    ];

    protected $casts = [
        'event_time' => 'datetime',
        'raw_payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * Idempotent persistence of a normalized tracking event.
     *
     * Deduplication key: external_event_id when the carrier supplies one,
     * otherwise the event tuple (time + description + location). Returns
     * the existing row when the event was already recorded.
     *
     * @param  array<string, mixed>  $attributes  normalized event fields
     */
    public static function firstOrCreateByExternal(int $shipmentId, array $attributes): self
    {
        $externalId = $attributes['external_event_id'] ?? null;

        $query = static::query()->where('shipment_id', $shipmentId);

        if ($externalId !== null && $externalId !== '') {
            $query->where('external_event_id', $externalId);
        } else {
            $query
                ->whereNull('external_event_id')
                ->where('event_time', $attributes['event_time'] ?? null)
                ->where('description', $attributes['description'] ?? null)
                ->where('city_locality', $attributes['city_locality'] ?? null);
        }

        $existing = $query->first();
        if ($existing) {
            return $existing;
        }

        return static::query()->create(array_merge([
            'shipment_id' => $shipmentId,
            'processed_at' => now(),
        ], $attributes));
    }

    /**
     * Events for a shipment ordered newest first.
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('event_time')->orderByDesc('id');
    }
}
