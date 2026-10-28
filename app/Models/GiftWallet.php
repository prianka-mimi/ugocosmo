<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GiftWallet - Virtual wallet for each user.
 *
 * Manages coin/currency balance for gifting during livestreams,
 * tips, and marketplace transactions.
 */
class GiftWallet extends Model
{
    use HasFactory;

    protected $table = 'gift_wallets';

    protected $fillable = [
        'user_id',
        'balance',
        'total_deposited',
        'total_withdrawn',
        'total_gifted',
        'total_received',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'total_deposited' => 'decimal:2',
        'total_withdrawn' => 'decimal:2',
        'total_gifted' => 'decimal:2',
        'total_received' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
