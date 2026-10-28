<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderBlockedDate extends Model
{
    use HasFactory;

    protected $table = 'provider_blocked_dates';

    protected $fillable = [
        'provider_id',
        'blocked_date',
        'start_time',
        'end_time',
        'reason',
    ];

    protected $casts = [
        'blocked_date' => 'date:Y-m-d',
        'start_time' => 'string',
        'end_time' => 'string',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'provider_id');
    }

    public function scopeByProvider($query, int $providerId)
    {
        return $query->where('provider_id', $providerId);
    }

    public function scopeByDate($query, string $date)
    {
        return $query->where('blocked_date', $date);
    }
}
