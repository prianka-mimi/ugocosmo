<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'author_id',
        'content',
        'parent_id',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'author_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class, 'comment_id');
    }

    /**
     * Scope to get only top-level comments (no parent).
     */
    public function scopeParents($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeForPost($query, int $postId)
    {
        return $query->where('post_id', $postId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Load replies on a collection of parent comments, ordered oldest-first.
     */
    public static function loadReplies($comments)
    {
        if ($comments->isEmpty()) {
            return $comments;
        }

        $commentIds = $comments->pluck('id');
        $replies = self::whereIn('parent_id', $commentIds)
            ->orderBy('created_at', 'asc')
            ->get()
            ->groupBy('parent_id');

        foreach ($comments as $comment) {
            $comment->setRelation('replies', $replies->get($comment->id, collect()));
        }

        return $comments;
    }

    /**
     * Bulk-load reactions for a collection of comments with reaction counts and the
     * authenticated user's reaction. Attaches a 'reactions_data' attribute to each comment.
     */
    public static function loadReactions($comments, ?int $profileId): void
    {
        if ($comments->isEmpty()) {
            return;
        }

        $allCommentIds = $comments->pluck('id')->toArray();

        // Also collect IDs from nested replies
        foreach ($comments as $comment) {
            if ($comment->relationLoaded('replies') && $comment->replies->isNotEmpty()) {
                $allCommentIds = array_merge($allCommentIds, $comment->replies->pluck('id')->toArray());
            }
        }

        $allCommentIds = array_unique($allCommentIds);

        // Fetch all reactions for these comments
        $allReactions = CommentReaction::whereIn('comment_id', $allCommentIds)->get();

        // Group reactions by comment_id
        $grouped = $allReactions->groupBy('comment_id');

        // Helper to set reaction data on a comment
        $setData = function (PostComment $comment) use ($grouped, $profileId) {
            $reactions = $grouped->get($comment->id, collect());

            // User's own reaction (boolean — whether the current user reacted)
            $userReacted = $profileId && $reactions->firstWhere('user_id', $profileId) !== null;

            $comment->reactions_data = [
                'total' => $reactions->count(),
                'user_reaction' => $userReacted,
            ];
        };

        foreach ($comments as $comment) {
            $setData($comment);
            if ($comment->relationLoaded('replies') && $comment->replies->isNotEmpty()) {
                foreach ($comment->replies as $reply) {
                    $setData($reply);
                }
            }
        }
    }
}
