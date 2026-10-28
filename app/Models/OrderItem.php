<?php

namespace App\Models;

use App\Services\Fulfillment\FulfillmentProviderResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'service_id',
        'ticket_id',
        'event_id',
        'title',
        'quantity',
        'unit_price',
        'total_price',
        'booking_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(EventTicket::class, 'ticket_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(EventTicket::class, 'order_item_id');
    }

    /**
     * Determine whether this order item requires shipping rate selection.
     */
    public function requiresShippingRate(): bool
    {
        return app(FulfillmentProviderResolver::class)
            ->resolveForOrderItem($this)
            ->requiresPrePaymentShippingRate($this);
    }
}
