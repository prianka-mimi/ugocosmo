<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShopifyStore extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'profile_id',
        'user_id',
        'store_domain',
        'access_token',
        'scopes',
        'installed_at',
        'shop_name',
        'status',
        'currency',
        'country',
        'timezone',
        'last_sync_at',
        'token_expires_at',
        'deleted_at', // required for softDisconnect() / app/uninstalled to persist
        'price_sync_policy',
        'image_sync_strategy',
        'inventory_sync_enabled',
    ];

    protected $casts = [
        'installed_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'inventory_sync_enabled' => 'boolean',
    ];

    // ─── Price Sync Policies ───────────────────────────────────────
    const PRICE_POLICY_SHOPIFY_ALWAYS = 'shopify_always';

    const PRICE_POLICY_SHOPIFY_NEVER = 'shopify_never';

    const PRICE_POLICY_SHOPIFY_IF_EMPTY = 'shopify_if_empty';

    const PRICE_POLICY_MANUAL = 'manual';

    // ─── Image Sync Strategies ──────────────────────────────────────
    const IMAGE_STRATEGY_ALWAYS = 'always';

    const IMAGE_STRATEGY_NEW_ONLY = 'new_only';

    const IMAGE_STRATEGY_SKIP = 'skip';

    /**
     * Apply the price sync policy to determine if a Shopify price should
     * overwrite the local product price.
     */
    public function shouldSyncPrice(float $shopifyPrice, ?float $localPrice): bool
    {
        return match ($this->price_sync_policy ?? self::PRICE_POLICY_SHOPIFY_ALWAYS) {
            self::PRICE_POLICY_SHOPIFY_ALWAYS => true,
            self::PRICE_POLICY_SHOPIFY_NEVER => false,
            self::PRICE_POLICY_SHOPIFY_IF_EMPTY => $localPrice === null || $localPrice == 0,
            self::PRICE_POLICY_MANUAL => false,
            default => true,
        };
    }

    /**
     * Apply the image sync strategy to determine if images should be downloaded.
     */
    public function shouldSyncImages(bool $hasLocalImages): bool
    {
        return match ($this->image_sync_strategy ?? self::IMAGE_STRATEGY_ALWAYS) {
            self::IMAGE_STRATEGY_ALWAYS => true,
            self::IMAGE_STRATEGY_NEW_ONLY => ! $hasLocalImages,
            self::IMAGE_STRATEGY_SKIP => false,
            default => true,
        };
    }

    /**
     * Resolve effective price sync policy, considering per-import override.
     */
    public function resolvePricePolicy(?string $importOverride): string
    {
        if ($importOverride !== null) {
            return $importOverride;
        }

        return $this->price_sync_policy ?? self::PRICE_POLICY_SHOPIFY_ALWAYS;
    }

    /**
     * Resolve effective image sync strategy, considering per-import override.
     */
    public function resolveImageStrategy(?string $importOverride): string
    {
        if ($importOverride !== null) {
            return $importOverride;
        }

        return $this->image_sync_strategy ?? self::IMAGE_STRATEGY_ALWAYS;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: stores connected by a specific profile.
     */
    public function scopeByProfile($query, int $profileId)
    {
        return $query->where('profile_id', $profileId);
    }

    /**
     * Scope: stores owned by a specific user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: find a store by its myshopify.com domain.
     */
    public function scopeByDomain($query, string $domain)
    {
        return $query->where('store_domain', $domain);
    }

    /**
     * Scope: active stores only.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')->whereNull('deleted_at');
    }

    /**
     * Get the myshopify.com URL for API calls.
     */
    public function apiDomain(): string
    {
        $domain = $this->store_domain;
        if (! str_contains($domain, 'myshopify.com')) {
            $domain = "{$domain}.myshopify.com";
        }

        return $domain;
    }
}
