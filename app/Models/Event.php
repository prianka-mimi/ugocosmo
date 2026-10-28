<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'organizer_id',
        'title',
        'description',
        'country',
        'city',
        'location',
        'event_date',
        'end_date',
        'price',
        'currency',
        'max_attendees',
        'ticket_prefix',
        'image_url',
        'video_url',
        'is_active',
        'is_published',
    ];

    protected $casts = [
        'event_date' => 'datetime',
        'end_date' => 'datetime',
        'price' => 'decimal:2',
        'max_attendees' => 'integer',
        'is_active' => 'boolean',
        'is_published' => 'boolean',
    ];

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'organizer_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(EventTag::class, 'event_event_tag', 'event_id', 'event_tag_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(EventSection::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(EventTicket::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(EventReview::class);
    }

    public function saves(): HasMany
    {
        return $this->hasMany(EventSave::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeByOrganizer($query, int $profileId)
    {
        return $query->where('organizer_id', $profileId);
    }

    public function scopeUpcoming($query)
    {
        return $query->where(function ($q) {
            $q->where('event_date', '>=', now())->orWhereNull('event_date');
        });
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
