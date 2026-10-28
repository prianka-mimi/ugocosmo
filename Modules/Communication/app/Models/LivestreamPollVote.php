<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivestreamPollVote extends Model
{
    protected $table = 'communication_livestream_poll_votes';

    protected $fillable = [
        'poll_id',
        'option_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'poll_id' => 'integer',
            'option_id' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(LivestreamPoll::class, 'poll_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(LivestreamPollOption::class, 'option_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
