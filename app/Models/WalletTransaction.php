<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WalletTransaction - Every financial movement in/out of a wallet.
 *
 * Tracks top-ups, gifts sent/received, withdrawals, and refunds
 * with full audit trail including Stripe payment intents.
 */
class WalletTransaction extends Model
{
    use HasFactory;

    protected $table = 'gift_wallet_transactions';

    protected $fillable = [
        'wallet_id',
        'user_id',
        'type',           // deposit, withdrawal, gift_sent, gift_received, refund
        'amount',
        'balance_after',
        'reference',
        'description',
        'status',         // pending, completed, failed
        'payment_method', // stripe, bank_transfer, wallet
        'stripe_payment_intent_id',
        'bank_account_id',
        'livestream_id',
        'recipient_id',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(GiftWallet::class, 'wallet_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'recipient_id');
    }

    public function livestream(): BelongsTo
    {
        return $this->belongsTo(Livestream::class);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
