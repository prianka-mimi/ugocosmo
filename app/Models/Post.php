<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'author_id',
        'content',
        'image_url',
        'video_url',
        'attached_item_type',
        'attached_item_id',
        'media',
        'gif_url',
        'emoji',
        'like_count',
        'comment_count',
        'share_count',
        'is_active',
        'is_reported',
        'original_post_id',
    ];

    protected $casts = [
        'media' => 'array',
        'like_count' => 'integer',
        'comment_count' => 'integer',
        'share_count' => 'integer',
        'is_active' => 'boolean',
        'is_reported' => 'boolean',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'author_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(PostReport::class);
    }

    public function saves(): HasMany
    {
        return $this->hasMany(PostSave::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(PostShare::class);
    }

    public function originalPost(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_post_id');
    }

    public function resharePosts(): HasMany
    {
        return $this->hasMany(self::class, 'original_post_id');
    }

    /**
     * Polymorphic relation to attached items (products, services, events, jobs).
     */
    public function attachedItem(): MorphTo
    {
        return $this->morphTo('attached_item', 'attached_item_type', 'attached_item_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeByAuthor($query, int $authorId)
    {
        return $query->where('author_id', $authorId);
    }

    public function scopePopular($query)
    {
        return $query->orderBy('like_count', 'desc');
    }

    public function scopeReshared($query)
    {
        return $query->whereNotNull('original_post_id');
    }
}
