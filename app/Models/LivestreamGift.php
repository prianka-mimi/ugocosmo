<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivestreamGift extends Model
{
    use HasFactory;

    protected $fillable = [
        'livestream_id',
        'sender_id',
        'gift_type',
        'amount',
        'message',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function livestream(): BelongsTo
    {
        return $this->belongsTo(Livestream::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'sender_id');
    }

    public function scopeForLivestream($query, int $livestreamId)
    {
        return $query->where('livestream_id', $livestreamId);
    }
}
