<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray($request): array
    {
        $items = $this->items->map(fn ($item) => [
            'id' => $item->id,
            'item_type' => $item->item_type,
            'product_id' => $item->product_id,
            'service_id' => $item->service_id,
            'event_id' => $item->event_id,
            'title' => $item->title,
            'image_url' => ImageManager::fileUrl($item->image_url),
            'quantity' => $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'total_price' => (float) $item->unit_price * $item->quantity,
        ]);

        $subtotal = round((float) $items->sum('total_price'), 2);

        return [
            'id' => $this->id,
            'items' => $items,
            'item_count' => $items->sum('quantity'),
            'subtotal' => $subtotal,
            'sub_total' => $subtotal,
            'shipping_total' => 0.00,
            'shipping_amount' => 0.00,
            'shipping_fee' => 0.00,
            'total' => $subtotal,
            'total_amount' => $subtotal,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
