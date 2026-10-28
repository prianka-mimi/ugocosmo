<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Profile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email',
        'full_name',
        'username',
        'avatar_url',
        'bio',
        'location',
        'website',
        'subscription_tier',
        'subscription_expires_at',
        'is_seller',
    ];

    protected $casts = [
        'subscription_expires_at' => 'datetime',
        'is_seller' => 'boolean',
    ];

    /**
     * Resolve avatar from profile first, then fall back to user.avatar.
     * Ensures avatar_url is never null when the user has uploaded via the User model.
     */
    public function getAvatarUrlAttribute(?string $value): ?string
    {
        if (! empty($value)) {
            return $value;
        }

        // Avoid an extra query if user is already loaded
        $userAvatar = $this->relationLoaded('user')
            ? $this->user?->avatar
            : $this->user()->value('avatar');

        return $userAvatar ?: null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'provider_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(JobListing::class, 'poster_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }

    public function stories(): HasMany
    {
        return $this->hasMany(Story::class, 'creator_id');
    }

    public function postLikes(): HasMany
    {
        return $this->hasMany(PostLike::class, 'user_id');
    }

    public function postSaves(): HasMany
    {
        return $this->hasMany(PostSave::class, 'user_id');
    }

    public function postShares(): HasMany
    {
        return $this->hasMany(PostShare::class, 'user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'user_id');
    }

    public function jobApplications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'applicant_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'customer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Order::class, 'seller_id');
    }

    public function aliexpressAccounts(): HasMany
    {
        return $this->hasMany(AliExpressAccount::class, 'profile_id');
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'seller_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(EventTicket::class, 'customer_id');
    }

    public function savedAddresses(): HasMany
    {
        return $this->hasMany(SavedAddress::class, 'profile_id');
    }
}
