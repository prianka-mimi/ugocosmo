<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'participant_1',
        'participant_2',
        'order_id',
        'message_type',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function participantOne(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'participant_1');
    }

    public function participantTwo(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'participant_2');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeForParticipant($query, int $profileId)
    {
        return $query->where('participant_1', $profileId)
            ->orWhere('participant_2', $profileId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('last_message_at', 'desc')->orderBy('created_at', 'desc');
    }
}
