<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Story extends Model
{
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'media_url',
        'media_type',
        'duration_seconds',
        'caption',
        'expires_at',
        'view_count',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'view_count' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'creator_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(StoryReaction::class);
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('expires_at', '>', now())
                ->orWhereNull('expires_at');
        });
    }

    public function scopeByCreator($query, int $creatorId)
    {
        return $query->where('creator_id', $creatorId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
