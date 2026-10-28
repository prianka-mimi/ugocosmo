<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallParticipant extends Model
{
    protected $table = 'call_participants';

    protected $fillable = [
        'call_log_id',
        'user_id',
        'status',
        'role',
        'joined_at',
        'left_at',
        'kicked_by_id',
    ];

    protected function casts(): array
    {
        return [
            'call_log_id' => 'integer',
            'user_id' => 'integer',
            'kicked_by_id' => 'integer',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(CallLog::class, 'call_log_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kickedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kicked_by_id');
    }
}
