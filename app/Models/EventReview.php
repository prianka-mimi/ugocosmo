<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'reviewer_id',
        'rating',
        'review_text',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reviewer_id');
    }

    public function scopeForEvent($query, int $eventId)
    {
        return $query->where('event_id', $eventId);
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
