<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LivestreamPoll extends Model
{
    protected $table = 'communication_livestream_polls';

    protected $fillable = [
        'call_log_id',
        'creator_id',
        'question',
        'status',
        'total_votes',
    ];

    protected function casts(): array
    {
        return [
            'call_log_id' => 'integer',
            'creator_id' => 'integer',
            'total_votes' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(CallLog::class, 'call_log_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(LivestreamPollOption::class, 'poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(LivestreamPollVote::class, 'poll_id');
    }
}
