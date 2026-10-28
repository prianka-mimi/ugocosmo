<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivestreamInvite extends Model
{
    use HasFactory;

    protected $fillable = [
        'livestream_id',
        'inviter_id',
        'invitee_id',
        'status',
    ];

    public function livestream(): BelongsTo
    {
        return $this->belongsTo(Livestream::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'inviter_id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'invitee_id');
    }

    public function scopeForLivestream($query, int $livestreamId)
    {
        return $query->where('livestream_id', $livestreamId);
    }
}
