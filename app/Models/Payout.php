<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payout - A payout sent to a seller from an escrow payment.
 *
 * After escrow is released, the seller receives their payout
 * minus platform fees to their bank account.
 */
class Payout extends Model
{
    use HasFactory;

    protected $table = 'payouts';

    protected $fillable = [
        'escrow_payment_id',
        'seller_id',
        'seller_bank_account_id',
        'amount',
        'status',
        'sent_at',
        'sent_by',
        'reference',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'sent_at' => 'datetime',
    ];

    public function escrowPayment(): BelongsTo
    {
        return $this->belongsTo(EscrowPayment::class, 'escrow_payment_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(SellerBankAccount::class, 'seller_bank_account_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'sent_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
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
