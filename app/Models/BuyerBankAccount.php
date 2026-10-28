<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BuyerBankAccount - Buyer's bank account for refunds.
 *
 * Stores limited information (last 4 digits only) for security.
 * Includes declaration that the account belongs to the buyer.
 */
class BuyerBankAccount extends Model
{
    use HasFactory;

    protected $table = 'buyer_bank_accounts';

    protected $fillable = [
        'buyer_id',
        'bank_name',
        'account_name',
        'account_number',
        'account_number_last4',
        'sort_code',
        'iban',
        'swift_code',
        'statement_path',
        'status',
        'is_own_account',
        'declaration_signed_at',
    ];

    protected $casts = [
        'is_own_account' => 'boolean',
        'declaration_signed_at' => 'datetime',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'buyer_id');
    }

    public function scopeByBuyer($query, int $buyerId)
    {
        return $query->where('buyer_id', $buyerId);
    }
}
