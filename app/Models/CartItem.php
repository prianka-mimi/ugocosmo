<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'service_id',
        'ticket_id',
        'event_id',
        'item_type',
        'title',
        'image_url',
        'quantity',
        'unit_price',
        'booking_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    public function getImageUrlAttribute(?string $value): ?string
    {
        if (! empty($value)) {
            return $value;
        }

        if ($this->relationLoaded('product') && $this->product) {
            return $this->product->image_url;
        }

        if ($this->relationLoaded('service') && $this->service) {
            return $this->service->image_url;
        }

        if ($this->relationLoaded('event') && $this->event) {
            return $this->event->image_url;
        }

        return null;
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
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
        return $this->hasMany(EventTicket::class, 'cart_item_id');
    }
}
