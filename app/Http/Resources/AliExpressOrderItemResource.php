<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AliExpressOrderItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $product = $this->product;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'total_price' => $this->total_price,
            'product' => $product ? [
                'id' => $product->id,
                'title' => $product->title,
                'image_url' => $product->image_url,
                'source' => $product->source,
                'stock_quantity' => $product->stock_quantity,
                'reserved_quantity' => $product->reserved_quantity,
                'available_quantity' => $product->availableQuantity(),
                'aliexpress_product_id' => $product->aliexpress_product_id,
            ] : null,
        ];
    }
}
