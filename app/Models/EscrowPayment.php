<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EscrowPayment - Holds payment in escrow between buyer and seller.
 *
 * Status flow: pending → held → released → paid_out
 * Cancelled at any stage if dispute resolved in favor of buyer.
 */
class EscrowPayment extends Model
{
    use HasFactory;

    protected $table = 'escrow_payments';

    protected $fillable = [
        'order_id',
        'amount',
        'payment_reference',
        'status',
        'buyer_id',
        'seller_id',
        'payment_received_at',
        'payment_confirmed_by',
        'escrow_released_at',
        'escrow_released_by',
        'payout_sent_at',
        'payout_sent_by',
        'payout_reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_received_at' => 'datetime',
        'escrow_released_at' => 'datetime',
        'payout_sent_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class, 'escrow_payment_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'escrow_payment_id');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByBuyer($query, int $profileId)
    {
        return $query->where('buyer_id', $profileId);
    }

    public function scopeBySeller($query, int $profileId)
    {
        return $query->where('seller_id', $profileId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
