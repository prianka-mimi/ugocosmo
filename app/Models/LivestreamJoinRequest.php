<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivestreamJoinRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'livestream_id',
        'requester_id',
        'status',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    public function livestream(): BelongsTo
    {
        return $this->belongsTo(Livestream::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'requester_id');
    }

    public function scopeForLivestream($query, int $livestreamId)
    {
        return $query->where('livestream_id', $livestreamId);
    }

    public function scopeByRequester($query, int $profileId)
    {
        return $query->where('requester_id', $profileId);
    }
}
