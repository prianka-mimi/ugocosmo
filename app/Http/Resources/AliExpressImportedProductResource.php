<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Seller-facing view of an imported AliExpress listing.
 *
 * Deliberately does NOT use ProductResource: that resource runs per-row
 * review average/count and is_saved queries, which would cause N+1 on
 * paginated listing screens (imported products, inventory overview).
 */
class AliExpressImportedProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->clean_description,
            'price' => $this->price,
            'currency' => $this->currency ?? 'USD',
            'category' => $this->whenLoaded('category', fn () => new ProductCategoryResource($this->category)),
            'image_url' => $this->image_url,
            'product_images' => $this->images ?? [],
            'is_active' => (bool) $this->is_active,
            'product_variants' => $this->product_variants ?? [],
            'sku' => $this->sku,

            // ── Inventory (available = stock − reserved) ────────────
            'stock_quantity' => $this->stock_quantity,
            'reserved_quantity' => $this->reserved_quantity,
            'available_quantity' => $this->availableQuantity(),
            'inventory_status' => $this->isOutOfStock()
                ? 'out_of_stock'
                : ($this->isLowStock((int) config('services.aliexpress.low_stock_threshold', 5))
                    ? 'low_stock'
                    : 'in_stock'),

            // ── AliExpress source metadata (cost basis, never tokens) ─
            'aliexpress_product_id' => $this->aliexpress_product_id,
            'aliexpress_product_url' => $this->aliexpress_product_url,
            'aliexpress_account' => $this->whenLoaded('aliexpressAccount', fn () => [
                'id' => $this->aliexpressAccount->id,
                'account_name' => $this->aliexpressAccount->account_name,
            ]),
            'cost_price' => $this->aliexpress_source_data['cost_price'] ?? null,
            'profit_margin' => $this->aliexpress_source_data['profit_margin'] ?? null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
