<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'reviewer_id',
        'rating',
        'review_text',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reviewer_id');
    }

    public function scopeForService($query, int $serviceId)
    {
        return $query->where('service_id', $serviceId);
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
