<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SellerBankAccount - Bank account information for sellers to receive payouts.
 *
 * Each seller can have multiple bank accounts, with one marked as primary.
 * Accounts must be verified before payouts can be sent.
 */
class SellerBankAccount extends Model
{
    use HasFactory;

    protected $table = 'seller_bank_accounts';

    protected $fillable = [
        'seller_id',
        'bank_name',
        'account_name',
        'account_number',
        'sort_code',
        'iban',
        'swift_code',
        'statement_path',
        'status',
        'is_primary',
        'is_verified',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_verified' => 'boolean',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'seller_bank_account_id');
    }

    public function scopeBySeller($query, int $sellerId)
    {
        return $query->where('seller_id', $sellerId);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }
}
