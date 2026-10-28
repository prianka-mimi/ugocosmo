<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'reviewer_id',
        'rating',
        'review_text',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reviewer_id');
    }

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByReviewer($query, int $profileId)
    {
        return $query->where('reviewer_id', $profileId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
