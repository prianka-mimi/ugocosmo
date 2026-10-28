<?php

namespace App\DTOs\GoogleMerchant;

use App\Models\Product;

/**
 * Maps a local Product model to the Google Merchant API ProductInput format.
 *
 * Product data specification:
 * https://developers.google.com/merchant/api/guides/products/product-data-specification
 */
final readonly class GoogleMerchantProductDTO
{
    private function __construct(
        public string $offerId,
        public string $title,
        public string $description,
        public string $link,
        public string $imageLink,
        public string $availability,
        public int $priceAmountMicros,
        public string $currencyCode,
        public string $condition,
        public string $contentLanguage,
        public string $feedLabel,
        public ?string $brand = null,
        public ?string $gtin = null,
        public ?string $mpn = null,
        public ?string $googleProductCategory = null,
        public ?int $quantity = null,
        public ?string $salePriceMicros = null,
        public array $additionalImageLinks = [],
        public array $shipping = [],
        public array $customAttributes = [],
    ) {}

    /**
     * Build from a local Product model.
     */
    public static function fromProduct(Product $product, ?string $productUrl = null): self
    {
        $primaryImage = self::resolvePrimaryImage($product);
        $additionalImages = self::resolveAdditionalImages($product);

        $availability = self::mapAvailability($product);
        $condition = self::mapCondition($product->condition ?? 'new');

        // Price in micros (amount * 1,000,000)
        $priceMicros = (int) round((float) ($product->price ?? 0) * 1_000_000);

        // Product link — use the provided URL or generate from admin domain
        $link = $productUrl ?? "https://admin.castilo.co.uk/product/{$product->id}";

        return new self(
            offerId: (string) $product->id,
            title: mb_substr((string) ($product->title ?? ''), 0, 150),
            description: mb_substr(strip_tags((string) ($product->description ?? '')), 0, 5000),
            link: $link,
            imageLink: $primaryImage,
            availability: $availability,
            priceAmountMicros: $priceMicros,
            currencyCode: strtoupper((string) ($product->currency ?? 'USD')),
            condition: $condition,
            contentLanguage: 'en',
            feedLabel: strtoupper((string) ($product->ship_from_address['country'] ?? 'US')),
            brand: $product->brand ?: null,
            gtin: $product->ean_upc ?: null,
            mpn: $product->sku ?: null,
            quantity: $product->stock_quantity !== null ? (int) $product->stock_quantity : null,
            additionalImageLinks: $additionalImages,
            customAttributes: self::buildCustomAttributes($product),
        );
    }

    /**
     * Convert to the Google Merchant API ProductInput request body.
     */
    public function toArray(): array
    {
        $attributes = [
            'title' => $this->title,
            'description' => $this->description,
            'link' => $this->link,
            'imageLink' => $this->imageLink,
            'availability' => $this->availability,
            'price' => [
                'amountMicros' => (string) $this->priceAmountMicros,
                'currencyCode' => $this->currencyCode,
            ],
            'condition' => $this->condition,
        ];

        if ($this->brand !== null && $this->brand !== '') {
            $attributes['brand'] = $this->brand;
        }

        if ($this->gtin !== null && $this->gtin !== '') {
            $attributes['gtins'] = [$this->gtin];
        }

        if ($this->mpn !== null && $this->mpn !== '') {
            $attributes['mpn'] = $this->mpn;
        }

        if ($this->googleProductCategory !== null && $this->googleProductCategory !== '') {
            $attributes['googleProductCategory'] = $this->googleProductCategory;
        }

        if (! empty($this->additionalImageLinks)) {
            $attributes['additionalImageLinks'] = $this->additionalImageLinks;
        }

        if (! empty($this->shipping)) {
            $attributes['shipping'] = $this->shipping;
        }

        if (! empty($this->customAttributes)) {
            $attributes['customAttributes'] = $this->customAttributes;
        }

        return [
            'offerId' => $this->offerId,
            'contentLanguage' => $this->contentLanguage,
            'feedLabel' => $this->feedLabel,
            'productAttributes' => $attributes,
        ];
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private static function resolvePrimaryImage(Product $product): string
    {
        // images is an array; first entry is primary
        if (is_array($product->images) && ! empty($product->images[0])) {
            return (string) $product->images[0];
        }

        if ($product->image_url && $product->image_url !== '') {
            return $product->image_url;
        }

        // Fallback — Google requires at least one image
        return '';
    }

    private static function resolveAdditionalImages(Product $product): array
    {
        if (! is_array($product->images) || count($product->images) <= 1) {
            return [];
        }

        return array_slice(array_values(array_filter($product->images)), 1, 9);
    }

    private static function mapAvailability(Product $product): string
    {
        if (! $product->is_active) {
            return 'OUT_OF_STOCK';
        }

        if ($product->stock_quantity !== null && (int) $product->stock_quantity <= 0) {
            return 'OUT_OF_STOCK';
        }

        return 'IN_STOCK';
    }

    private static function mapCondition(string $condition): string
    {
        return match (strtolower($condition)) {
            'new' => 'NEW',
            'refurbished' => 'REFURBISHED',
            'used', 'pre-owned' => 'USED',
            default => 'NEW',
        };
    }

    private static function buildCustomAttributes(Product $product): array
    {
        $attrs = [];

        if ($product->weight !== null && (float) $product->weight > 0) {
            $attrs[] = [
                'name' => 'weight',
                'value' => (string) round((float) $product->weight, 2),
            ];
        }

        return $attrs;
    }
}
