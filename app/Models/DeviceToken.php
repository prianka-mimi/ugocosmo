<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fcm_token',
        'device_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The profile (user) who owns this device token.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }

    /**
     * Scope: only active (valid) tokens.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: tokens belonging to a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: tokens of a specific platform type.
     */
    public function scopeOfType($query, string $deviceType)
    {
        return $query->where('device_type', $deviceType);
    }
}
