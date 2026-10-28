<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CallLog extends Model
{
    public $timestamps = false;

    protected $table = 'call_logs';

    protected $fillable = [
        'conversation_id',
        'initiator_id',
        'ended_by_id',
        'call_type',
        'engine',
        'room_name',
        'title',
        'description',
        'thumbnail_url',
        'background_image_url',
        'video_url',
        'stream_layout',
        'allow_gift_bet',
        'custom_questions',
        'is_live',
        'viewer_count',
        'likes_count',
        'status',
        'duration_seconds',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'initiator_id' => 'integer',
            'ended_by_id' => 'integer',
            'duration_seconds' => 'integer',
            'is_live' => 'boolean',
            'viewer_count' => 'integer',
            'likes_count' => 'integer',
            'allow_gift_bet' => 'boolean',
            'custom_questions' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CallParticipant::class, 'call_log_id');
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(CommunicationLike::class, 'likeable');
    }
}
