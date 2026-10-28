<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyImportLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_import_id',
        'shopify_store_id',
        'user_id',
        'product_id',
        'shopify_product_id',
        'product_title',
        'action',
        'reason',
        'error_message',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(ShopifyImport::class, 'shopify_import_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(ShopifyStore::class, 'shopify_store_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
