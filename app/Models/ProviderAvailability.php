<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderAvailability extends Model
{
    use HasFactory;

    protected $table = 'provider_availability';

    protected $fillable = [
        'provider_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_available',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'start_time' => 'string',
        'end_time' => 'string',
        'is_available' => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'provider_id');
    }

    public function scopeByProvider($query, int $providerId)
    {
        return $query->where('provider_id', $providerId);
    }

    public function scopeByDay($query, int $dayOfWeek)
    {
        return $query->where('day_of_week', $dayOfWeek);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }
}
