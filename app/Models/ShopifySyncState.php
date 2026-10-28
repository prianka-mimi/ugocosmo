<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifySyncState extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_store_id',
        'resource_type',
        'last_synced_at',
        'last_updated_at',
        'last_shopify_id',
        'total_count',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'last_updated_at' => 'datetime',
        'total_count' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(ShopifyStore::class, 'shopify_store_id');
    }

    /**
     * Scope: sync states for a specific store.
     */
    public function scopeByStore($query, int $storeId)
    {
        return $query->where('shopify_store_id', $storeId);
    }

    /**
     * Scope: sync states for a specific resource type.
     */
    public function scopeByResource($query, string $resourceType)
    {
        return $query->where('resource_type', $resourceType);
    }
}
