<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'requester_id',
        'recipient_id',
        'connection_type',
        'status',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'requester_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'recipient_id');
    }

    public function scopeByRequester($query, int $userId)
    {
        return $query->where('requester_id', $userId);
    }

    public function scopeByRecipient($query, int $userId)
    {
        return $query->where('recipient_id', $userId);
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
