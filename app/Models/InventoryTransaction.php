<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'seller_id',
        'aliexpress_account_id',
        'type',
        'quantity',
        'stock_before',
        'stock_after',
        'reserved_before',
        'reserved_after',
        'reference_type',
        'reference_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
        'reserved_before' => 'integer',
        'reserved_after' => 'integer',
        'reference_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AliExpressAccount::class, 'aliexpress_account_id');
    }

    public function scopeBySeller($query, int $profileId)
    {
        return $query->where('seller_id', $profileId);
    }

    public function scopeByProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
