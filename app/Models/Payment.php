<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'payment_intent_id',
        'stripe_transaction_id',
        'amount',
        'currency',
        'payment_method',
        'payment_status',
        'paid_at',
        'webhook_payload',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'webhook_payload' => 'array',
    ];

    /**
     * Get the order associated with the payment.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the user who made the payment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
