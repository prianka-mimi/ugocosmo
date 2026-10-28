<?php

namespace App\DTOs\Shopify;

class ShopifyVariantDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly float $price,
        public readonly ?float $compareAtPrice,
        public readonly ?string $sku,
        public readonly ?string $barcode,
        public readonly int $inventoryQuantity,
        public readonly ?string $inventoryItemId,
        public readonly float $weight,
        public readonly string $weightUnit,
        public readonly bool $requiresShipping,
        public readonly ?string $imageUrl,
        public readonly array $selectedOptions = [],
    ) {}

    /**
     * Create from Shopify GraphQL variant node.
     */
    public static function fromGraphQL(array $node): self
    {
        $inventoryItemId = null;
        if (! empty($node['inventoryItem']['id'] ?? null)) {
            $parts = explode('/', $node['inventoryItem']['id']);
            $inventoryItemId = end($parts) ?: null;
        }

        $selectedOptions = [];
        foreach ($node['selectedOptions'] ?? [] as $opt) {
            $selectedOptions[] = [
                'name' => $opt['name'] ?? '',
                'value' => $opt['value'] ?? '',
            ];
        }

        return new self(
            id: (string) ($node['id'] ?? ''),
            title: $node['title'] ?? 'Default',
            price: (float) ($node['price'] ?? 0),
            compareAtPrice: ! empty($node['compareAtPrice']) ? (float) $node['compareAtPrice'] : null,
            sku: $node['sku'] ?? null,
            barcode: $node['barcode'] ?? null,
            inventoryQuantity: (int) ($node['inventoryQuantity'] ?? 0),
            inventoryItemId: $inventoryItemId,
            weight: (float) ($node['weight'] ?? 0),
            weightUnit: $node['weightUnit'] ?? 'kg',
            requiresShipping: (bool) ($node['requiresShipping'] ?? true),
            imageUrl: $node['image']['src'] ?? null,
            selectedOptions: $selectedOptions,
        );
    }

    /**
     * Create from Shopify REST API variant array.
     */
    public static function fromRest(array $variant): self
    {
        $selectedOptions = [];
        if (isset($variant['option1'])) {
            $selectedOptions[] = ['name' => 'Option 1', 'value' => $variant['option1']];
        }
        if (isset($variant['option2'])) {
            $selectedOptions[] = ['name' => 'Option 2', 'value' => $variant['option2']];
        }
        if (isset($variant['option3'])) {
            $selectedOptions[] = ['name' => 'Option 3', 'value' => $variant['option3']];
        }

        return new self(
            id: (string) ($variant['id'] ?? ''),
            title: $variant['title'] ?? 'Default',
            price: (float) ($variant['price'] ?? 0),
            compareAtPrice: ! empty($variant['compare_at_price']) ? (float) $variant['compare_at_price'] : null,
            sku: $variant['sku'] ?? null,
            barcode: $variant['barcode'] ?? null,
            inventoryQuantity: (int) ($variant['inventory_quantity'] ?? 0),
            inventoryItemId: (string) ($variant['inventory_item_id'] ?? ''),
            weight: (float) ($variant['weight'] ?? 0),
            weightUnit: $variant['weight_unit'] ?? 'kg',
            requiresShipping: (bool) ($variant['requires_shipping'] ?? true),
            // REST variants expose only image_id (a reference to a product image), not a URL.
            imageUrl: null,
            selectedOptions: $selectedOptions,
        );
    }

    /**
     * Convert to array for API responses / storage.
     */
    public function toArray(): array
    {
        return [
            'shopify_variant_id' => $this->id,
            'title' => $this->title,
            'price' => $this->price,
            'compare_at_price' => $this->compareAtPrice,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'inventory_quantity' => $this->inventoryQuantity,
            'inventory_item_id' => $this->inventoryItemId,
            'weight' => $this->weight,
            'weight_unit' => $this->weightUnit,
            'requires_shipping' => $this->requiresShipping,
            'image_url' => $this->imageUrl,
            'selected_options' => $this->selectedOptions,
        ];
    }
}
