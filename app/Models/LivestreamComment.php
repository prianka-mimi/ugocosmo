<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivestreamComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'livestream_id',
        'user_id',
        'content',
    ];

    public function livestream(): BelongsTo
    {
        return $this->belongsTo(Livestream::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }

    public function scopeForLivestream($query, int $livestreamId)
    {
        return $query->where('livestream_id', $livestreamId);
    }

    public function scopeOldestFirst($query)
    {
        return $query->orderBy('created_at', 'asc');
    }
}
