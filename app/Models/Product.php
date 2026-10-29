<?php

namespace App\Models;

use App\DTOs\AliExpress\AliExpressProductDTO;
use App\DTOs\ShippingOptionDTO;
use App\Services\AliExpressProductClient;
use App\Services\Fulfillment\FulfillmentProviderResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        // ── Ownership ──────────────────────────────────────────────
        'seller_id',

        // ── 1. Basic Information ───────────────────────────────────
        'title',
        'description',
        'price',
        'currency',
        'compare_at_price',
        'category_id',
        'stock_quantity',
        'product_type',
        'condition',
        'product_variants',

        // ── 2. Details ─────────────────────────────────────────────
        'specifications',
        'features_benefits',
        'weight',
        'wholesale_product',
        'moq',
        'bulk_pricing',
        'brand',
        'sku',
        'ean_upc',

        // ── 3. Shipping ────────────────────────────────────────────
        'ship_from_address',
        'shipping_options',
        'allowed_shipping_options',
        'shipping_cost',
        'aliexpress_shipping_cost',
        'shipping_tiers',
        'package_dimensions',
        'handling_time',
        'estimated_delivery',
        'estimated_delivery_time',
        'shipping_info',
        'shipping_strategy',
        'location',

        // ── 4. Media & Status ──────────────────────────────────────
        'image_url',
        'images',
        'video_url',
        'digital_download_url',
        'is_active',

        // ── 5. Shopify Import (External Source) ─────────────────────
        'shopify_product_id',
        'shopify_store_url',
        'shopify_store_id',
        'shopify_variant_id',
        'shopify_inventory_item_id',
        'shopify_updated_at',
        'shopify_published_at',
        'barcode',
        'source',
        'sync_status',
        'last_synced_at',
        'shopify_source_data',

        // ── 6. AliExpress Import (External Source) ───────────────────
        'aliexpress_product_id',
        'aliexpress_sku',
        'aliexpress_account_id',
        'aliexpress_product_url',
        'aliexpress_updated_at',
        'aliexpress_source_data',
        'reserved_quantity',

        // ── 7. CSV Import (External Source) ──────────────────────────
        'csv_source_data',

        // ── 8. Etsy Import (External Source) ───────────────────────
        'etsy_listing_id',
        'etsy_account_id',
        'etsy_shop_id',
        'etsy_shop_name',
        'etsy_state',
        'etsy_product_url',
        'etsy_updated_at',
        'etsy_source_data',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'weight' => 'decimal:2',
        'stock_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'is_active' => 'boolean',
        'wholesale_product' => 'boolean',
        'moq' => 'integer',
        'bulk_pricing' => 'array',
        'images' => 'array',
        'product_variants' => 'array',
        'shipping_options' => 'array',
        'shipping_tiers' => 'array',
        'package_dimensions' => 'array',
        'shopify_updated_at' => 'datetime',
        'shopify_published_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'shopify_source_data' => 'array',
        'aliexpress_updated_at' => 'datetime',
        'aliexpress_source_data' => 'array',
        'csv_source_data' => 'array',
        'etsy_updated_at' => 'datetime',
        'etsy_source_data' => 'array',
    ];

    // Append the custom attribute to array/JSON output
    protected $appends = [
        'local_location',
    ];

    // Hide the original 'location' column from array/JSON output
    protected $hidden = [
        'location',
    ];

    /**
     * Retrieve the database 'location' as 'local_location'
     */
    public function getLocalLocationAttribute(): ?string
    {
        return $this->attributes['location'] ?? null;
    }

    /**
     * Allow setting the database 'location' via 'local_location'
     */
    public function setLocalLocationAttribute(?string $value): void
    {
        $this->attributes['location'] = $value;
    }

    // ─── Accessors ──────────────────────────────────────────────────

    public function setDescriptionAttribute(?string $value): void
    {
        $this->attributes['description'] = AliExpressProductDTO::cleanHtmlDescription($value) ?: null;
    }

    public function getCleanDescriptionAttribute(): ?string
    {
        return AliExpressProductDTO::cleanHtmlDescription($this->description) ?: null;
    }

    public function getAllowedShippingOptionsAttribute(): array
    {
        return $this->getEnabledShippingOptionKeys();
    }

    public function setAllowedShippingOptionsAttribute(mixed $value): void
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        $this->shipping_options = is_array($value) ? array_values(array_filter($value)) : null;
    }

    public function getAliexpressShippingCostAttribute(): float
    {
        if (is_array($this->aliexpress_source_data) && isset($this->aliexpress_source_data['shipping_cost']) && is_numeric($this->aliexpress_source_data['shipping_cost'])) {
            return round((float) $this->aliexpress_source_data['shipping_cost'], 2);
        }

        return round((float) ($this->shipping_cost ?? 0.0), 2);
    }

    public function setAliexpressShippingCostAttribute(mixed $value): void
    {
        $cost = is_numeric($value) ? round((float) $value, 2) : 0.0;
        $this->shipping_cost = $cost;

        if (is_array($this->aliexpress_source_data)) {
            $src = $this->aliexpress_source_data;
            $src['shipping_cost'] = $cost;
            $this->aliexpress_source_data = $src;
        }
    }

    public function getEstimatedDeliveryTimeAttribute(): ?string
    {
        if (is_array($this->aliexpress_source_data)) {
            $src = $this->aliexpress_source_data;
            if (! empty($src['shipping']['delivery_time'])) {
                return (string) $src['shipping']['delivery_time'];
            }
            if (! empty($src['shipping']['estimated_delivery'])) {
                return (string) $src['shipping']['estimated_delivery'];
            }
            if (! empty($src['estimated_delivery'])) {
                return (string) $src['estimated_delivery'];
            }
        }

        return $this->estimated_delivery;
    }

    public function setEstimatedDeliveryTimeAttribute(?string $value): void
    {
        $this->estimated_delivery = $value;

        if (is_array($this->aliexpress_source_data)) {
            $src = $this->aliexpress_source_data;
            if (! isset($src['shipping']) || ! is_array($src['shipping'])) {
                $src['shipping'] = [];
            }
            $src['shipping']['delivery_time'] = $value;
            $this->aliexpress_source_data = $src;
        }
    }

    public function getImageUrlAttribute(?string $value): ?string
    {
        if (! empty($value)) {
            return $value;
        }

        if (! empty($this->images) && is_array($this->images) && ! empty($this->images[0])) {
            return $this->images[0];
        }

        if (is_array($this->aliexpress_source_data)) {
            if (! empty($this->aliexpress_source_data['image'])) {
                return $this->aliexpress_source_data['image'];
            }
            if (! empty($this->aliexpress_source_data['images']) && is_array($this->aliexpress_source_data['images']) && ! empty($this->aliexpress_source_data['images'][0])) {
                return $this->aliexpress_source_data['images'][0];
            }
        }

        if (is_array($this->shopify_source_data)) {
            if (! empty($this->shopify_source_data['image']['src'])) {
                return $this->shopify_source_data['image']['src'];
            }
            if (! empty($this->shopify_source_data['images'][0]['src'])) {
                return $this->shopify_source_data['images'][0]['src'];
            }
        }

        return null;
    }

    // ─── Relationships ─────────────────────────────────────────────

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ProductTag::class, 'product_product_tag', 'product_id', 'product_tag_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class, 'product_id');
    }

    public function saves(): HasMany
    {
        return $this->hasMany(ProductSave::class, 'product_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_id');
    }

    public function aliexpressAccount(): BelongsTo
    {
        return $this->belongsTo(AliExpressAccount::class, 'aliexpress_account_id');
    }

    public function etsyAccount(): BelongsTo
    {
        return $this->belongsTo(EtsyAccount::class, 'etsy_account_id');
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'product_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBySeller($query, int $profileId)
    {
        return $query->where('seller_id', $profileId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Retrieve the model for a bound value.
     * Supports both internal primary key (id) and external IDs (AliExpress, Shopify, Etsy).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field) {
            return parent::resolveRouteBinding($value, $field);
        }

        $user = Auth::guard('jwt')->user() ?? Auth::user();
        if ($user) {
            $candidateIds = array_filter(array_unique([
                (int) $user->id,
                (int) ($user->profile?->id ?? 0),
            ]));

            // Prioritize product owned by this authenticated seller
            $owned = $this->where(function ($q) use ($candidateIds, $user) {
                if (! empty($candidateIds)) {
                    $q->whereIn('seller_id', $candidateIds);
                }
                $q->orWhereHas('aliexpressAccount', fn ($aq) => $aq->where('user_id', $user->id))
                    ->orWhereHas('seller', fn ($sq) => $sq->where('user_id', $user->id));
            })->where(function ($q) use ($value) {
                $q->where('aliexpress_product_id', (string) $value)
                    ->orWhere('shopify_product_id', (string) $value)
                    ->orWhere('etsy_listing_id', (string) $value)
                    ->orWhere('id', $value);
            })->first();

            if ($owned) {
                return $owned;
            }
        }

        if (is_numeric($value) && strlen((string) $value) <= 10) {
            $found = $this->where('id', $value)->first();
            if ($found) {
                return $found;
            }
        }

        return $this->where('aliexpress_product_id', (string) $value)
            ->orWhere('shopify_product_id', (string) $value)
            ->orWhere('etsy_listing_id', (string) $value)
            ->orWhere('id', $value)
            ->first();
    }

    public function scopeByCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeByCondition($query, string $condition)
    {
        return $query->where('condition', $condition);
    }

    public function scopeByTag($query, string $tagName)
    {
        return $query->whereHas('tags', function ($q) use ($tagName) {
            $q->where('name', $tagName);
        });
    }

    public function scopeWholesale($query)
    {
        return $query->where('wholesale_product', true);
    }

    public function scopeSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeByAliExpressAccount($query, int $accountId)
    {
        return $query->where('aliexpress_account_id', $accountId);
    }

    public function scopeByEtsySeller($query, int $profileId)
    {
        return $query->where('source', 'etsy')->where('seller_id', $profileId);
    }

    // ─── Inventory helpers (state inspection only) ─────────────────

    public function availableQuantity(): int
    {
        return max(0, (int) $this->stock_quantity - (int) $this->reserved_quantity);
    }

    public function isLowStock(int $threshold): bool
    {
        return (int) $this->stock_quantity > 0 && (int) $this->stock_quantity <= $threshold;
    }

    public function isOutOfStock(): bool
    {
        return (int) $this->stock_quantity <= 0;
    }

    // ─── Shipping helpers ──────────────────────────────────────────

    /**
     * Get list of normalized canonical shipping option keys supported by this product.
     *
     * @return array<int, string>
     */
    public function getEnabledShippingOptionKeys(): array
    {
        $rawOptions = $this->shipping_options;

        if (empty($rawOptions)) {
            return [ShippingOptionDTO::STANDARD_SHIPPING];
        }

        $keys = [];
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $key => $val) {
                if (is_int($key) && is_string($val)) {
                    $norm = ShippingOptionDTO::normalizeKey($val);
                    if ($norm) {
                        $keys[] = $norm;
                    }
                } elseif (is_string($key) && $val) {
                    $norm = ShippingOptionDTO::normalizeKey($key);
                    if ($norm) {
                        $keys[] = $norm;
                    }
                }
            }
        }

        return ! empty($keys) ? array_values(array_unique($keys)) : [ShippingOptionDTO::STANDARD_SHIPPING];
    }

    /**
     * Check if this product supports a specific shipping option.
     */
    public function supportsShippingOption(string $option): bool
    {
        $canonical = ShippingOptionDTO::normalizeKey($option);
        if (! $canonical) {
            return false;
        }

        return in_array($canonical, $this->getEnabledShippingOptionKeys(), true);
    }

    /**
     * Resolve shipping cost for a given shipping option.
     * Free Shipping, Local Delivery, and Local Pickup always have 0.00 shipping cost.
     */
    public function getShippingOptionCost(string $option): float
    {
        $canonical = ShippingOptionDTO::normalizeKey($option);
        if (! $canonical) {
            return (float) ($this->shipping_cost ?? 0.00);
        }

        // Free shipping, local delivery, and local pickup always cost 0.00
        if (in_array($canonical, [
            ShippingOptionDTO::FREE_SHIPPING,
            ShippingOptionDTO::LOCAL_SHIPPING,
            ShippingOptionDTO::LOCAL_PICKUP,
            ShippingOptionDTO::MULTI_ITEM_PICKUP,
            ShippingOptionDTO::HEAVY_ITEM_PICKUP,
        ], true) || str_ends_with($canonical, '_pickup')) {
            return 0.00;
        }

        $tiers = is_array($this->shipping_tiers) ? $this->shipping_tiers : [];

        $possibleKeys = [
            $canonical.'_cost',
            $canonical,
            str_replace('_shipping', '', $canonical).'_cost',
            str_replace('_shipping', '', $canonical),
        ];

        foreach ($possibleKeys as $k) {
            if (isset($tiers[$k]) && is_numeric($tiers[$k])) {
                return round((float) $tiers[$k], 2);
            }
        }

        $aliCost = $this->aliexpress_shipping_cost;
        if ($this->source === 'aliexpress' && $aliCost > 0) {
            return round((float) $aliCost, 2);
        }

        return round((float) ($this->shipping_cost ?? 0.00), 2);
    }

    /**
     * Get rich array of enabled ShippingOptionDTO objects.
     *
     * @return array<int, ShippingOptionDTO>
     */
    public function getEnabledShippingOptions(): array
    {
        $enabledKeys = $this->getEnabledShippingOptionKeys();
        $tiers = is_array($this->shipping_tiers) ? $this->shipping_tiers : [];
        $options = [];

        foreach ($enabledKeys as $key) {
            $cost = $this->getShippingOptionCost($key);
            $dto  = ShippingOptionDTO::make($key, $cost);

            if ($key === ShippingOptionDTO::FREE_SHIPPING) {
                $dto = new ShippingOptionDTO(
                    key: $dto->key,
                    name: 'Free Shipping',
                    cost: 0.00,
                    estimatedDelivery: $tiers['free_shipping_estimated_delivery'] ?? '5-7 business days',
                    description: 'Free shipping provided by seller.',
                    isAvailable: true,
                    ruleCode: 'FREE_SHIPPING',
                );
            } elseif ($key === ShippingOptionDTO::LOCAL_SHIPPING || $key === 'local_pickup') {
                $dto = new ShippingOptionDTO(
                    key: ShippingOptionDTO::LOCAL_SHIPPING,
                    name: 'Local Delivery',
                    cost: 0.00,
                    estimatedDelivery: $tiers['local_shipping_estimated_delivery'] ?? ($tiers['local_pickup_estimated_delivery'] ?? '1-2 business days'),
                    description: 'Direct local delivery within city/region.',
                    isAvailable: true,
                    ruleCode: 'LOCAL_SHIPPING',
                );
            }

            $options[] = $dto;
        }

        return $options;
    }

    /**
     * Get authentic rating for AliExpress product with auto-fetch and self-healing cache.
     */
    public function getAliExpressRating(): float
    {
        if ($this->source !== 'aliexpress') {
            return 0.0;
        }

        $src = is_array($this->aliexpress_source_data) ? $this->aliexpress_source_data : [];
        if (! empty($src['rating']) && (float) $src['rating'] > 0) {
            return round((float) $src['rating'], 1);
        }

        if (! empty($this->aliexpress_product_id)) {
            try {
                $eval = app(AliExpressProductClient::class)->productEvaluations($this->aliexpress_product_id);
                if (! empty($eval['rating']) && (float) $eval['rating'] > 0) {
                    $rating = round((float) $eval['rating'], 1);
                    $src['rating'] = $rating;
                    $src['reviews_count'] = (int) ($eval['total_reviews'] ?? $eval['ratings_count'] ?? 0);
                    $src['reviews'] = $src['reviews_count'];
                    $this->aliexpress_source_data = $src;
                    $this->saveQuietly();

                    return $rating;
                }
            } catch (\Throwable $e) {
                // Ignore API failure and fallback
            }
        }

        return 0.0;
    }

    /**
     * Get authentic reviews/ratings count for AliExpress product.
     */
    public function getAliExpressReviewsCount(): int
    {
        if ($this->source !== 'aliexpress') {
            return 0;
        }

        $src = is_array($this->aliexpress_source_data) ? $this->aliexpress_source_data : [];
        if (! empty($src['reviews_count']) && (int) $src['reviews_count'] > 0) {
            return (int) $src['reviews_count'];
        }
        if (! empty($src['reviews']) && (int) $src['reviews'] > 0) {
            return (int) $src['reviews'];
        }

        if (! empty($this->aliexpress_product_id)) {
            try {
                $eval = app(AliExpressProductClient::class)->productEvaluations($this->aliexpress_product_id);
                $count = (int) ($eval['total_reviews'] ?? $eval['ratings_count'] ?? 0);
                if ($count > 0) {
                    $src['reviews_count'] = $count;
                    $src['reviews'] = $count;
                    if (! empty($eval['rating'])) {
                        $src['rating'] = round((float) $eval['rating'], 1);
                    }
                    $this->aliexpress_source_data = $src;
                    $this->saveQuietly();

                    return $count;
                }
            } catch (\Throwable $e) {
                // Ignore API failure and fallback
            }
        }

        return 0;
    }

    /**
     * Check if the product is a digital or non-physical item.
     */
    public function isDigital(): bool
    {
        $digitalTypes = config('shipping.digital_product_types', [
            'digital',
            'digital_download',
            'virtual',
            'service',
        ]);

        return in_array(strtolower((string) $this->product_type), $digitalTypes, true);
    }

    /**
     * Determine whether this product requires ShipEngine shipping rate selection
     * before payment can proceed.
     */
    public function requiresShippingRate(): bool
    {
        return app(FulfillmentProviderResolver::class)
            ->resolveForProduct($this)
            ->requiresPrePaymentShippingRate($this);
    }
}
