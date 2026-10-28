<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class LivestreamComment extends Model
{
    protected $table = 'communication_livestream_comments';

    protected $fillable = [
        'call_log_id',
        'user_id',
        'parent_id',
        'comment',
        'likes_count',
        'replies_count',
    ];

    protected function casts(): array
    {
        return [
            'call_log_id' => 'integer',
            'user_id' => 'integer',
            'parent_id' => 'integer',
            'likes_count' => 'integer',
            'replies_count' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(CallLog::class, 'call_log_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at', 'asc');
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(CommunicationLike::class, 'likeable');
    }
}
