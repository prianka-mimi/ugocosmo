<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Livestream extends Model
{
    use HasFactory;

    protected $fillable = [
        'host_id',
        'title',
        'description',
        'thumbnail_url',
        'is_live',
        'viewer_count',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'is_live' => 'boolean',
        'viewer_count' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'host_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(LivestreamComment::class);
    }

    public function gifts(): HasMany
    {
        return $this->hasMany(LivestreamGift::class);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(LivestreamJoinRequest::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(LivestreamInvite::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LivestreamItem::class);
    }

    public function scopeLive($query)
    {
        return $query->where('is_live', true);
    }
}
