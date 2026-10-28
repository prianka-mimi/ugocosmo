<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTicket extends Model
{
    use HasFactory;

    protected $table = 'event_tickets';

    protected $fillable = [
        'event_id',
        'customer_id',
        'section_id',
        'seat_id',
        'cart_item_id',
        'order_item_id',
        'ticket_number',
        'customer_name',
        'customer_email',
        'customer_dob',
        'customer_address',
        'status',
        'purchased_at',
        'used_at',
    ];

    protected $casts = [
        'purchased_at' => 'datetime',
        'used_at' => 'datetime',
        'customer_dob' => 'date',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'customer_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(EventSection::class, 'section_id');
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(EventSeat::class, 'seat_id');
    }

    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(CartItem::class, 'cart_item_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function scopeByCustomer($query, int $profileId)
    {
        return $query->where('customer_id', $profileId);
    }

    public function scopeForEvent($query, int $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
