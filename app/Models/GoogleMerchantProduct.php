<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleMerchantProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'merchant_id', 'offer_id', 'content_language', 'feed_label',
        'resource_name', 'product_resource_name', 'sync_status', 'last_error',
        'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    // ─── Constants ────────────────────────────────────────────────

    const STATUS_PENDING = 'pending';

    const STATUS_SYNCED = 'synced';

    const STATUS_ERROR = 'error';

    const STATUS_DELETED = 'deleted';

    // ─── Relationships ────────────────────────────────────────────

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeSynced($query)
    {
        return $query->where('sync_status', self::STATUS_SYNCED);
    }
}
