<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentReaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'comment_id',
        'user_id',
        'reaction',
    ];

    public const array REACTIONS = ['like', 'love', 'haha', 'wow', 'sad', 'angry'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(PostComment::class, 'comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }

    public function scopeForComment($query, int $commentId)
    {
        return $query->where('comment_id', $commentId);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
