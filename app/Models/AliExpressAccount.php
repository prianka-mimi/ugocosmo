<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AliExpressAccount extends Model
{
    use HasFactory, SoftDeletes;

    // Explicit table name: Laravel would otherwise derive `ali_express_accounts`
    // from the class name, which does not match the migration.
    protected $table = 'aliexpress_accounts';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const REGION_SG = 'sg';

    public const REGION_GLOBAL = 'global';

    protected $fillable = [
        'profile_id',
        'user_id',
        'aliexpress_member_id',
        'account_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'refresh_token_valid_until',
        'scopes',
        'region',
        'status',
        'connected_at',
        'last_sync_at',
        'last_error',
        'deleted_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'refresh_token_valid_until' => 'datetime',
        'connected_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    // Defense-in-depth: tokens must never leak if the model is serialized.
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(AliExpressImport::class, 'aliexpress_account_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'aliexpress_account_id');
    }

    public function apiLogs(): HasMany
    {
        return $this->hasMany(AliExpressApiLog::class, 'aliexpress_account_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(AliExpressSyncLog::class, 'aliexpress_account_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByProfile($query, int $profileId)
    {
        return $query->where('profile_id', $profileId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)->whereNull('deleted_at');
    }

    public function scopeWithExpiringToken($query, int $minutes = 30)
    {
        return $query->active()
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addMinutes($minutes));
    }

    // ─── Entity helpers (state inspection only) ────────────────────

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->deleted_at === null;
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at === null || $this->token_expires_at->isPast();
    }

    public function isRefreshTokenExpired(): bool
    {
        return $this->refresh_token_valid_until === null
            || $this->refresh_token_valid_until->isPast();
    }

    public function needsReconnect(): bool
    {
        return $this->isTokenExpired() && $this->isRefreshTokenExpired();
    }
}
