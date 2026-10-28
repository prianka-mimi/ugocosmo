<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'blocker_id',
        'blocked_id',
        'reason',
    ];

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'blocker_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'blocked_id');
    }

    public function scopeByBlocker($query, int $userId)
    {
        return $query->where('blocker_id', $userId);
    }
}
