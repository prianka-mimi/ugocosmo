<?php

namespace App\DTOs\Shopify;

class ShopifyProductDTO
{
    public function __construct(
        public readonly string $shopifyId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly float $price,
        public readonly ?float $compareAtPrice,
        public readonly ?string $sku,
        public readonly ?string $barcode,
        public readonly ?string $brand,
        public readonly ?string $productType,
        public readonly int $inventoryQuantity,
        public readonly float $weight,
        public readonly string $weightUnit,
        public readonly string $status,
        public readonly ?string $vendor,
        public readonly array $tags,
        public readonly ?string $imageUrl,
        public readonly array $images,
        public readonly array $variants,
        public readonly ?string $shopifyCreatedAt,
        public readonly ?string $shopifyUpdatedAt,
        public readonly ?string $handle = null,
        public readonly ?string $seoTitle = null,
        public readonly ?string $seoDescription = null,
        public readonly array $options = [],
    ) {}

    /**
     * Create from a Shopify GraphQL product node.
     */
    public static function fromGraphQL(array $node): self
    {
        $variants = array_map(
            fn (array $edge) => ShopifyVariantDTO::fromGraphQL($edge['node'] ?? $edge),
            $node['variants']['edges'] ?? []
        );

        $firstVariant = $variants[0] ?? null;

        $images = array_map(
            fn (array $edge) => $edge['node']['originalSrc'] ?? ($edge['src'] ?? ''),
            $node['images']['edges'] ?? $node['images'] ?? []
        );

        $tags = array_map('trim', explode(',', $node['tags'] ?? ''));

        // Parse options from GraphQL
        $options = [];
        foreach ($node['options'] ?? [] as $opt) {
            $options[] = [
                'name' => $opt['name'] ?? '',
                'values' => $opt['values'] ?? [],
            ];
        }

        // Extract SEO
        $seo = $node['seo'] ?? [];
        $seoTitle = ! empty($seo['title']) ? $seo['title'] : null;
        $seoDescription = ! empty($seo['description']) ? $seo['description'] : null;

        return new self(
            shopifyId: (string) ($node['id'] ?? ''),
            title: $node['title'] ?? '',
            description: strip_tags($node['descriptionHtml'] ?? $node['body_html'] ?? ''),
            price: (float) ($firstVariant?->price ?? $node['variants'][0]['price'] ?? 0),
            compareAtPrice: $firstVariant?->compareAtPrice ?? null,
            sku: $firstVariant?->sku ?? null,
            barcode: $firstVariant?->barcode ?? null,
            brand: $node['vendor'] ?? null,
            productType: $node['productType'] ?? null,
            inventoryQuantity: $firstVariant?->inventoryQuantity ?? 0,
            weight: (float) ($firstVariant?->weight ?? 0),
            weightUnit: $firstVariant?->weightUnit ?? 'kg',
            status: $node['status'] ?? 'active',
            vendor: $node['vendor'] ?? null,
            tags: $tags,
            imageUrl: $images[0] ?? null,
            images: $images,
            variants: $variants,
            shopifyCreatedAt: $node['createdAt'] ?? null,
            shopifyUpdatedAt: $node['updatedAt'] ?? null,
            handle: $node['handle'] ?? null,
            seoTitle: $seoTitle,
            seoDescription: $seoDescription,
            options: $options,
        );
    }

    /**
     * Create from a Shopify REST API product array.
     */
    public static function fromRest(array $product): self
    {
        $variants = array_map(
            fn (array $v) => ShopifyVariantDTO::fromRest($v),
            $product['variants'] ?? []
        );

        $firstVariant = $variants[0] ?? null;

        $images = array_map(
            fn (array $img) => $img['src'] ?? '',
            $product['images'] ?? []
        );

        $tags = array_map('trim', explode(',', $product['tags'] ?? ''));

        // Parse options from REST API
        $options = [];
        for ($i = 1; $i <= 3; $i++) {
            $name = $product["option{$i}"] ?? null;
            $values = $product["option{$i}_values"] ?? null;
            if ($name) {
                $options[] = [
                    'name' => $name,
                    'values' => $values ?? [],
                ];
            }
        }

        // If option values not directly available, extract from variants
        if (empty($options) && ! empty($variants)) {
            $optionNames = [];
            foreach ($variants as $variant) {
                foreach ($variant->selectedOptions as $opt) {
                    if (! isset($optionNames[$opt['name']])) {
                        $optionNames[$opt['name']] = [];
                    }
                    if (! in_array($opt['value'], $optionNames[$opt['name']])) {
                        $optionNames[$opt['name']][] = $opt['value'];
                    }
                }
            }
            foreach ($optionNames as $name => $values) {
                $options[] = ['name' => $name, 'values' => $values];
            }
        }

        return new self(
            shopifyId: (string) ($product['id'] ?? ''),
            title: $product['title'] ?? '',
            description: strip_tags($product['body_html'] ?? ''),
            price: $firstVariant?->price ?? (float) ($product['variants'][0]['price'] ?? 0),
            compareAtPrice: $firstVariant?->compareAtPrice ?? null,
            sku: $firstVariant?->sku ?? null,
            barcode: $firstVariant?->barcode ?? null,
            brand: $product['vendor'] ?? null,
            productType: $product['product_type'] ?? null,
            inventoryQuantity: $firstVariant?->inventoryQuantity ?? 0,
            weight: (float) ($firstVariant?->weight ?? 0),
            weightUnit: $firstVariant?->weightUnit ?? 'kg',
            status: $product['status'] ?? 'active',
            vendor: $product['vendor'] ?? null,
            tags: $tags,
            imageUrl: $product['image']['src'] ?? $images[0] ?? null,
            images: $images,
            variants: $variants,
            shopifyCreatedAt: $product['created_at'] ?? null,
            shopifyUpdatedAt: $product['updated_at'] ?? null,
            handle: $product['handle'] ?? null,
            seoTitle: $product['seo_title'] ?? $product['metafields_global_title_tag'] ?? null,
            seoDescription: $product['seo_description'] ?? $product['metafields_global_description_tag'] ?? null,
            options: $options,
        );
    }

    /**
     * Build the product_variants JSON structure from Shopify options and variants.
     * Maps to the local format: [{name, type, options: [{value, priceModifier, stock, sku}]}]
     */
    public function buildLocalVariants(): array
    {
        if (empty($this->options) && empty($this->variants)) {
            return [];
        }

        $localVariants = [];

        // Build from options first
        foreach ($this->options as $option) {
            $optionEntry = [
                'name' => $option['name'] ?? '',
                'type' => 'select',
                'options' => [],
            ];

            foreach ($option['values'] as $value) {
                // Find matching variants for this option value
                $matchingVariants = array_filter($this->variants, function ($v) use ($option, $value) {
                    foreach ($v->selectedOptions as $so) {
                        if ($so['name'] === $option['name'] && $so['value'] === $value) {
                            return true;
                        }
                    }

                    return false;
                });

                $matchingVariant = ! empty($matchingVariants) ? reset($matchingVariants) : null;

                $optionEntry['options'][] = [
                    'value' => $value,
                    'priceModifier' => $matchingVariant ? ($matchingVariant->price - $this->price) : 0,
                    'price_adjustment' => $matchingVariant ? ($matchingVariant->price - $this->price) : 0,
                    'stockQuantity' => $matchingVariant?->inventoryQuantity ?? 0,
                    'stock' => $matchingVariant?->inventoryQuantity ?? 0,
                    'sku' => $matchingVariant?->sku ?? null,
                    'shopify_variant_id' => $matchingVariant?->id ?? null,
                    'image_url' => $matchingVariant?->imageUrl ?? null,
                ];
            }

            $localVariants[] = $optionEntry;
        }

        return $localVariants;
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(): array
    {
        return [
            'shopify_id' => $this->shopifyId,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'compare_at_price' => $this->compareAtPrice,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'brand' => $this->brand,
            'product_type' => $this->productType,
            'inventory_quantity' => $this->inventoryQuantity,
            'weight' => $this->weight,
            'weight_unit' => $this->weightUnit,
            'status' => $this->status,
            'vendor' => $this->vendor,
            'tags' => $this->tags,
            'image_url' => $this->imageUrl,
            'images' => $this->images,
            'variants' => array_map(fn (ShopifyVariantDTO $v) => $v->toArray(), $this->variants),
            'handle' => $this->handle,
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
            'options' => $this->options,
            'created_at' => $this->shopifyCreatedAt,
            'updated_at' => $this->shopifyUpdatedAt,
        ];
    }
}
