<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
    ];

    /**
     * Get the user that owns the social account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include accounts by provider.
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope a query to only include accounts by provider ID.
     */
    public function scopeByProviderId($query, string $providerId)
    {
        return $query->where('provider_id', $providerId);
    }

    /**
     * Helper to find a social account by provider and provider ID.
     */
    public static function findByProvider(string $provider, string $providerId): ?self
    {
        return self::byProvider($provider)->byProviderId($providerId)->first();
    }
}
