<?php

namespace App\Http\Resources;

use App\DTOs\Shopify\ShopifyProductDTO;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopifyProductPreviewResource extends JsonResource
{
    public function toArray($request): array
    {
        if ($this->resource instanceof ShopifyProductDTO) {
            return $this->resource->toArray();
        }

        // Raw Shopify product array or Eloquent model
        if (is_array($this->resource)) {
            $dto = ShopifyProductDTO::fromRest($this->resource);

            return $dto->toArray();
        }

        // Local product model
        return [
            'shopify_id' => $this->shopify_product_id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => (float) $this->price,
            'compare_at_price' => $this->compare_at_price,
            'sku' => $this->sku,
            'brand' => $this->brand,
            'product_type' => $this->product_type,
            'inventory_quantity' => (int) $this->stock_quantity,
            'image_url' => $this->image_url,
            'images' => $this->images ?? [],
            'is_active' => (bool) $this->is_active,
            'sync_status' => $this->sync_status ?? 'pending',
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
