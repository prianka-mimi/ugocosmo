<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InventoryTransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'title' => $this->product->title,
                'image_url' => $this->product->image_url,
                'stock_quantity' => $this->product->stock_quantity,
                'reserved_quantity' => $this->product->reserved_quantity,
            ]),
            'type' => $this->type,
            'quantity' => $this->quantity,
            'stock_before' => $this->stock_before,
            'stock_after' => $this->stock_after,
            'reserved_before' => $this->reserved_before,
            'reserved_after' => $this->reserved_after,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
