<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

#[Fillable([
    'account_type',
    'name',
    'username',
    'email',
    'mobile_number',
    'address_line_1',
    'address_line_2',
    'city',
    'postal_code',
    'country',
    'password',
    'otp',
    'otp_expires_at',
    'new_email',
    'role',
    'avatar',
    'occupation',
    'dob',
    'marital_status',
    'bio',
    'skills',
    'interests',
    'cv_resume',
    'id_card_image',
    'card_image',
    'is_verified',
    'verified_at',
    'is_approved',
    'status',
    'terms_accepted',
    'terms_accepted_at',
    'onboarding_completed',
    'onboarding_completed_at',
    'cover_photo',
])]
#[Hidden(['password', 'remember_token', 'otp'])]
class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'bio' => 'string',
            'dob' => 'date',
            'skills' => 'array',
            'interests' => 'array',
            'otp_expires_at' => 'datetime',
            'password' => 'hashed',
            'verified_at' => 'datetime',
            'terms_accepted' => 'boolean',
            'terms_accepted_at' => 'datetime',
            'onboarding_completed' => 'boolean',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(UserSubscription::class)->where('is_active', true)->latest();
    }

    /**
     * Allow login for admin+user roles freely; supervisor needs active status.
     */
    public function canLogin(): bool
    {
        if ($this->role === 'admin') {
            return true;
        }

        return ($this->getRawOriginal('status') ?? 'inactive') === 'active';
    }

    public function scopeByEmail($query, string $email)
    {
        return $query->where('email', $email);
    }

    public function scopeByOtp($query, string $otp)
    {
        return $query->where('otp', $otp);
    }

    public function scopeByProvider($query, string $provider, string $providerId)
    {
        return $query->whereHas('socialAccounts', function ($q) use ($provider, $providerId) {
            $q->byProvider($provider)->byProviderId($providerId);
        });
    }

    public static function findByEmail(string $email): ?self
    {
        return self::byEmail($email)->first();
    }

    public static function findByOtp(string $email, string $otp): ?self
    {
        return self::byEmail($email)->byOtp($otp)->first();
    }

    public static function findByProvider(string $provider, string $providerId): ?self
    {
        return self::byProvider($provider, $providerId)->first();
    }

    protected static function booted(): void
    {
        static::saving(function ($user) {
            // Normalize legacy role values to lowercase without whitespace.
            if (isset($user->role)) {
                $user->role = strtolower(trim((string) $user->role));
            }

            // Always protect the primary admin account.
            if ($user->email === 'admin@admin.com' && $user->role !== 'admin') {
                $user->role = 'admin';
            }
        });

        static::saved(function ($user) {
            // Keep spatie roles in sync with the legacy `role` column
            try {
                if (in_array($user->role, ['admin', 'supervisor'])) {
                    // Use findOrCreate to ensure the role exists in Spatie's roles table
                    // before trying to assign it. This prevents RoleDoesNotExist exceptions
                    // when the seeder hasn't run yet or roles were deleted.
                    Role::findOrCreate($user->role, 'web');
                    if (! $user->hasRole($user->role)) {
                        $user->syncRoles([$user->role]);
                    }
                } else {
                    // Non-admin/supervisor users should have no spatie roles
                    if ($user->roles->isNotEmpty()) {
                        $user->syncRoles([]);
                    }
                }
            } catch (\Throwable $e) {
                // Log the error but never let role syncing break the save operation
                Log::error('Role sync failed for user '.($user->id ?? 'new').': '.$e->getMessage());
            }
        });
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function getStatusAttribute($value): string
    {
        if ($this->role === 'admin') {
            return 'active';
        }

        return $value ?? 'inactive';
    }

    public function hasBlocked(int $userId): bool
    {
        $blockedUsers = $this->blocked_users ?? [];

        if (! is_array($blockedUsers)) {
            return false;
        }

        return in_array($userId, $blockedUsers, true);
    }

    public function deviceTokens(): HasManyThrough
    {
        return $this->hasManyThrough(
            DeviceToken::class,
            Profile::class,
            'user_id',
            'user_id',
            'id',
            'id'
        );
    }

    public function routeNotificationForSms(): ?string
    {
        return $this->mobile_number;
    }

    public function routeNotificationForFcm(): array
    {
        $profileId = $this->profile?->id ?? $this->id;

        return DeviceToken::forUser($profileId)->active()->pluck('fcm_token')->all();
    }

    public function receivesNotificationChannel(string $type, string $channel): bool
    {
        $profileId = $this->profile?->id ?? $this->id;
        $prefs = NotificationPreference::forUser($profileId);

        return match ($channel) {
            'database' => $prefs->isInAppEnabled($type),
            'push' => $prefs->isPushEnabled($type),
            'email' => $prefs->isEmailEnabled($type),
            'sms' => true,
            default => true,
        };
    }
}
