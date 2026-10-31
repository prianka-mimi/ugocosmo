<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        $src = ($this->source === 'aliexpress' && is_array($this->aliexpress_source_data))
            ? $this->aliexpress_source_data
            : [];

        // Rating:
        // 1. If local database has customer reviews with avg rating > 0, use it.
        // 2. Otherwise if AliExpress source_data has rating > 0, use it.
        // 3. Otherwise if AliExpress product, auto-fetch authentic rating via getAliExpressRating().
        // 4. Fallback to 0.0.
        $rating = 0.0;
        if (isset($this->reviews_avg_rating) && (float) $this->reviews_avg_rating > 0) {
            $rating = round((float) $this->reviews_avg_rating, 1);
        } elseif (! empty($src['rating']) && (float) $src['rating'] > 0) {
            $rating = round((float) $src['rating'], 1);
        } elseif ($this->source === 'aliexpress' && method_exists($this->resource, 'getAliExpressRating')) {
            $rating = (float) $this->resource->getAliExpressRating();
        }

        // Rating count (number of reviews):
        // 1. If local database reviews count > 0, use it.
        // 2. Otherwise if AliExpress source_data has reviews_count > 0, use it.
        // 3. Otherwise if AliExpress product, auto-fetch authentic reviews count via getAliExpressReviewsCount().
        // 4. Fallback to 0.
        $ratingCount = 0;
        if (isset($this->reviews_count) && (int) $this->reviews_count > 0) {
            $ratingCount = (int) $this->reviews_count;
        } elseif (! empty($src['reviews_count']) && (int) $src['reviews_count'] > 0) {
            $ratingCount = (int) $src['reviews_count'];
        } elseif (! empty($src['reviews']) && (int) $src['reviews'] > 0) {
            $ratingCount = (int) $src['reviews'];
        } elseif ($this->source === 'aliexpress' && method_exists($this->resource, 'getAliExpressReviewsCount')) {
            $ratingCount = (int) $this->resource->getAliExpressReviewsCount();
        }

        return [
            'id' => $this->id,
            'seller' => [
                'id'          => $this->seller?->id,
                'full_name'   => $this->seller?->full_name,
                'username'    => $this->seller?->username,
                'avatar_url'  => ImageManager::avatarUrl(
                    $this->seller?->avatar_url
                        ?? $this->seller?->user?->avatar
                        ?? null
                ),
                'is_verified' => (bool) $this->seller?->user?->is_verified,
            ],

            // ── 1. Basic Information ───────────────────────────────
            'title'            => $this->title,
            'description'      => $this->clean_description,
            'price'            => (float) $this->price,
            'currency'         => $this->currency ?? 'USD',
            'compare_at_price' => $this->compare_at_price ? (float) $this->compare_at_price : ($src['original_price'] ?? ($src['original_price_min'] ?? null)),
            'category'         => $this->whenLoaded('category', fn() => new ProductCategoryResource($this->category)),
            'stock_quantity'   => (int) $this->stock_quantity,
            'product_type'     => strtolower((string) ($this->product_type ?? '')) === 'digital' ? 'digital' : 'physical',
            'condition'        => $this->condition ?? ($this->source === 'aliexpress' ? 'new' : null),
            'product_variants' => $this->castVariants($this->product_variants ?? []),

            // ── 2. Details ─────────────────────────────────────────
            'brand'            => $this->brand ?? ($src['brand'] ?? null),
            'sku'              => $this->sku ?? ($this->aliexpress_sku ?? ($src['skus'][0]['sku_code'] ?? null)),
            'ean_upc'          => $this->ean_upc,
            'tags'             => $this->whenLoaded('tags', fn() => $this->tags->pluck('name')),
            'specifications'   => $this->specifications ?? (! empty($src['attributes']) && is_array($src['attributes']) ? implode("\n", array_filter(array_map(fn($a) => isset($a['name'], $a['value']) ? "{$a['name']}: {$a['value']}" : null, $src['attributes']))) : null),
            'features_benefits' => $this->features_benefits,
            'weight'           => $this->weight ? (float) $this->weight : ($src['package']['weight'] ?? ($src['weight'] ?? null)),
            'wholesale_product' => (bool) $this->wholesale_product,
            'moq'              => $this->moq ? (int) $this->moq : null,
            'bulk_pricing'     => $this->castBulkPricing($this->bulk_pricing ?? []),

            // ── 3. Shipping ────────────────────────────────────────
            'ship_from_address'  => $this->ship_from_address,
            'package_dimensions' => $this->castDimensions($this->package_dimensions ?? ($src['package'] ?? null)),
            'handling_time'      => $this->handling_time,
            'local_location'     => $this->location,
            'shipping_strategy'  => $this->shipping_strategy ?? 'manual',

            // AliExpress vs Local Product Shipping Distinction
            ...($this->source === 'aliexpress' || ! empty($this->aliexpress_product_id) ? [
                'is_aliexpress_product' => true,
                'aliexpress_shipping_cost' => (float) ($this->aliexpress_shipping_cost ?? $this->shipping_cost ?? 0.0),
                'estimated_delivery_time' => (string) ($this->estimated_delivery_time ?? $this->estimated_delivery ?? '10-15 business days'),
                'shipping_fee' => ((float) ($this->aliexpress_shipping_cost ?? $this->shipping_cost ?? 0.0) > 0)
                    ? '$' . number_format((float) ($this->aliexpress_shipping_cost ?? $this->shipping_cost ?? 0.0), 2)
                    : (isset($src['is_free_shipping']) && $src['is_free_shipping'] ? 'Free Shipping' : '$0.00'),
                'shipping_display' => 'Shipping: $' . number_format((float) ($this->aliexpress_shipping_cost ?? $this->shipping_cost ?? 0.0), 2) . ', ETA: ' . ($this->estimated_delivery_time ?? $this->estimated_delivery ?? '10-15 business days'),
                'is_free_shipping' => isset($src['is_free_shipping']) ? (bool) $src['is_free_shipping'] : ((float) ($this->aliexpress_shipping_cost ?? $this->shipping_cost ?? 0.0) <= 0),
                'carrier' => (! empty($src['carrier']) && ! str_contains(strtolower((string) $src['carrier']), 'aliexpress'))
                    ? (string) $src['carrier']
                    : 'Standard Tracked Shipping',
                'allowed_shipping_options' => [],
                'available_shipping_options' => [],
                'rate_selection_required' => false,
            ] : [
                'is_aliexpress_product' => false,
                'aliexpress_shipping_cost' => null,
                'estimated_delivery_time' => null,
                // 'allowed_shipping_options' => array_values(array_map(fn ($k) => $this->formatShippingOptionLabel($k), $this->getEnabledShippingOptionKeys())),
                // 'shipping_display' => 'Available: '.implode(', ', array_map(fn ($k) => $this->formatShippingOptionLabel($k), $this->getEnabledShippingOptionKeys())),
                // 'shipping_fee' => $this->supportsShippingOption('free_shipping') ? 'Free Shipping' : 'Calculated at checkout',
                // 'is_free_shipping' => $this->supportsShippingOption('free_shipping'),
                'carrier' => null,
                // 'available_shipping_options' => array_values(array_map(fn ($k) => $this->formatShippingOptionLabel($k), $this->getEnabledShippingOptionKeys())),
                'shipping_options' => array_values(array_map(fn($dto) => [
                    'key'                => $dto->key,
                    'name'               => $dto->name,
                    'cost'               => (float) $dto->cost,
                    'estimated_delivery' => $dto->estimatedDelivery,
                    'description'        => $dto->description,
                ], $this->getEnabledShippingOptions())),
                'rate_selection_required' => true,
            ]),

            // ── 4. Media & Status ──────────────────────────────────
            'image_url'      => ImageManager::fileUrl($this->image_url),
            'product_images' => $this->images ? array_map(fn($img) => ImageManager::fileUrl($img), $this->images) : [],
            'video_url'      => ImageManager::fileUrl($this->video_url),
            'is_active'      => (bool) $this->is_active,
            'status'         => $this->is_active ? 'active' : 'inactive',
            'source'         => $this->source ?? 'local',
            'aliexpress_product_id' => $this->aliexpress_product_id,
            'shopify_product_id'    => $this->shopify_product_id,
            'etsy_listing_id'       => $this->etsy_listing_id,
            'rating'         => $rating,
            'rating_count'   => $ratingCount,
            'is_saved'       => (bool) (auth('jwt')->user()?->profile?->id
                ? $this->saves()->where('user_id', auth('jwt')->user()->profile->id)->exists()
                : false),
            'digital_download_url' => $this->digital_download_url,

            // ── 5. Recommendations ─────────────────────────────────
            'frequently_bought_together' => $this->when(
                isset($this->frequently_bought_together),
                fn() => ProductResource::collection($this->frequently_bought_together)
            ),
            'customers_also_bought' => $this->when(
                isset($this->customers_also_bought),
                fn() => ProductResource::collection($this->customers_also_bought)
            ),

            // ── Timestamps ─────────────────────────────────────────
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function castVariants(array $variants): array
    {
        return array_map(function ($variant) {
            $variant['options'] = array_map(function ($opt) {
                if (isset($opt['priceModifier']))    $opt['priceModifier']  = (float) $opt['priceModifier'];
                if (isset($opt['price_adjustment'])) $opt['price_adjustment'] = (float) $opt['price_adjustment'];
                if (isset($opt['stockQuantity']))    $opt['stockQuantity']  = (int) $opt['stockQuantity'];
                if (isset($opt['stock']))            $opt['stock']          = (int) $opt['stock'];
                return $opt;
            }, $variant['options'] ?? []);
            return $variant;
        }, $variants);
    }

    private function castBulkPricing(array $pricing): array
    {
        return array_map(function ($tier) {
            if (isset($tier['min_quantity']))  $tier['min_quantity']  = (int) $tier['min_quantity'];
            if (isset($tier['price_per_unit'])) $tier['price_per_unit'] = (float) $tier['price_per_unit'];
            return $tier;
        }, $pricing);
    }

    private function castDimensions(?array $dims): ?array
    {
        if (empty($dims)) return null;
        if (isset($dims['length'])) $dims['length'] = (float) $dims['length'];
        if (isset($dims['width']))  $dims['width']  = (float) $dims['width'];
        if (isset($dims['height'])) $dims['height'] = (float) $dims['height'];
        return $dims;
    }

    protected function formatShippingOptionLabel(string $key): string
    {
        $dto = \App\DTOs\ShippingOptionDTO::make($key);

        return match ($dto->key) {
            \App\DTOs\ShippingOptionDTO::STANDARD_SHIPPING => 'Standard',
            \App\DTOs\ShippingOptionDTO::EXPRESS_SHIPPING => 'Express',
            \App\DTOs\ShippingOptionDTO::INTERNATIONAL_SHIPPING => 'International',
            default => $dto->name,
        };
    }
}