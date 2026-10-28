<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'title',
        'description',
        'price',
        'currency',
        'price_type',
        'service_type',
        'delivery_location',
        'category_id',
        'estimated_delivery_time',
        'is_insured',
        'is_certified',
        'image_url',
        'images',
        'video_url',
        'location',
        'address',
        'country',
        'city',
        'service_area',
        'areas_of_coverage',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_insured' => 'boolean',
        'is_certified' => 'boolean',
        'images' => 'array',
        'areas_of_coverage' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'provider_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ServicePackage::class, 'service_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'service_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ServiceTag::class, 'service_service_tag', 'service_id', 'service_tag_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ServiceReview::class, 'service_id');
    }

    public function saves(): HasMany
    {
        return $this->hasMany(ServiceSave::class, 'service_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByProvider($query, int $profileId)
    {
        return $query->where('provider_id', $profileId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeByTag($query, string $tagName)
    {
        return $query->whereHas('tags', function ($q) use ($tagName) {
            $q->where('name', $tagName);
        });
    }
}
