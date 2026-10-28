<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical fulfillment of an order through ShipEngine (rates, labels,
 * tracking). Holds only safe external references — never credentials.
 */
class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'seller_id',
        'rate_id',
        'external_shipment_id',
        'external_label_id',
        'carrier_id',
        'carrier_code',
        'service_code',
        'status',
        'label_status',
        'tracking_number',
        'tracking_status',
        'shipping_cost',
        'currency',
        'ship_date',
        'estimated_delivery_date',
        'label_format',
        'label_download_url',
        'voided',
        'voided_at',
        'label_created_at',
        'shipped_at',
        'delivered_at',
        'last_tracking_synced_at',
        'error_message',
        'ship_from_snapshot',
        'ship_to_snapshot',
        'package_snapshot',
    ];

    protected $casts = [
        'shipping_cost' => 'decimal:2',
        'ship_date' => 'date',
        'estimated_delivery_date' => 'date',
        'voided' => 'boolean',
        'voided_at' => 'datetime',
        'label_created_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'last_tracking_synced_at' => 'datetime',
        'ship_from_snapshot' => 'array',
        'ship_to_snapshot' => 'array',
        'package_snapshot' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(ShipmentTrackingEvent::class)->latestFirst();
    }

    public function scopeForOrder($query, int $orderId)
    {
        return $query->where('order_id', $orderId);
    }

    public function scopeBySeller($query, int $profileId)
    {
        return $query->whereHas('order', fn ($q) => $q->where('seller_id', $profileId));
    }

    public function scopeByBuyer($query, int $profileId)
    {
        return $query->whereHas('order', fn ($q) => $q->where('buyer_id', $profileId));
    }

    // ─── State inspection (model-local, no side effects) ────────────

    public function hasActiveLabel(): bool
    {
        return (! empty($this->external_label_id) || ! empty($this->getRawOriginal('label_download_url'))) && ! $this->voided;
    }

    /**
     * Get the download URL for the shipping label (triggers file download).
     */
    public function getLabelDownloadUrlAttribute(?string $value): ?string
    {
        if (empty($value) && ! $this->hasActiveLabel()) {
            return null;
        }

        return url("/api/shipping/shipments/{$this->id}/label?download=1");
    }

    /**
     * Get the inline preview URL for the shipping label (renders in browser).
     */
    public function getLabelPreviewUrlAttribute(): ?string
    {
        if (! $this->hasActiveLabel() && empty($this->external_label_id)) {
            return null;
        }

        return url("/api/shipping/shipments/{$this->id}/label?preview=1");
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [ShipmentStatus::Voided->value, ShipmentStatus::Delivered->value], true);
    }

    /**
     * Whether the shipment has physically left the seller (carrier has it).
     */
    public function isInCarriage(): bool
    {
        return in_array($this->status, [
            ShipmentStatus::Shipped->value,
            ShipmentStatus::InTransit->value,
            ShipmentStatus::OutForDelivery->value,
            ShipmentStatus::Delivered->value,
        ], true);
    }

    /**
     * Route model binding: allow lookup by ID or Tracking Number.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where('id', $value)->orWhere('tracking_number', $value)->firstOrFail();
    }
}
