<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LivestreamPollOption extends Model
{
    protected $table = 'communication_livestream_poll_options';

    protected $fillable = [
        'poll_id',
        'option_text',
        'votes_count',
    ];

    protected function casts(): array
    {
        return [
            'poll_id' => 'integer',
            'votes_count' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(LivestreamPoll::class, 'poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(LivestreamPollVote::class, 'option_id');
    }
}
