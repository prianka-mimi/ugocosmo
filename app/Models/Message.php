<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'content',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'sender_id');
    }

    public function scopeByConversation($query, int $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    public function scopeBySender($query, int $profileId)
    {
        return $query->where('sender_id', $profileId);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeOldestFirst($query)
    {
        return $query->orderBy('created_at', 'asc');
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
