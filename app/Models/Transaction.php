<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'booking_id',
        'subscription_id',
        'buyer_id',
        'seller_id',
        'transaction_type',
        'amount',
        'platform_fee',
        'seller_revenue',
        'tax_amount',
        'stripe_payment_id',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'seller_revenue' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }
}
