<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'age_visibility',
        'employment_history_visibility',
        'qualifications_visibility',
        'profile_visibility',
        'show_online_status',
        'message_permission',
    ];

    protected $casts = [
        'show_online_status' => 'boolean',
    ];

    /**
     * Default privacy settings applied when a user has no saved settings.
     */
    public const DEFAULTS = [
        'age_visibility' => 'private',
        'employment_history_visibility' => 'verified',
        'qualifications_visibility' => 'verified',
        'profile_visibility' => 'public',
        'show_online_status' => true,
        'message_permission' => 'everyone',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get or create privacy settings for a user with defaults.
     */
    public static function forUser(int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            self::DEFAULTS
        );
    }
}
