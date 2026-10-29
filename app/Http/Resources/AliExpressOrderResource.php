<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class AliExpressOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        $status = OrderStatus::tryFrom((string) $this->status);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $status?->label() ?? $this->status,
            'buyer' => $this->whenLoaded('buyer', fn () => [
                'id' => $this->buyer->id,
                'full_name' => $this->buyer->full_name,
                'username' => $this->buyer->username,
                'avatar_url' => $this->buyer->avatar_url,
            ]),
            'seller' => $this->whenLoaded('seller', fn () => [
                'id' => $this->seller->id,
                'full_name' => $this->seller->full_name,
                'username' => $this->seller->username,
            ]),
            'items' => AliExpressOrderItemResource::collection($this->whenLoaded('items')),
            'subtotal' => $this->subtotal,
            'buyer_fee' => $this->buyer_fee,
            'seller_fee' => $this->seller_fee,
            'platform_fee_total' => $this->platform_fee_total,
            'total' => $this->total,
            'shipping_address' => $this->shipping_address,
            'transection_number' => $this->transection_number,
            'transaction_number' => $this->transaction_number,
            'tracking_number' => $this->carrier_tracking_number,

            // ── AliExpress dropshipping (automated supplier order) ──
            'aliexpress_order_numbers' => $this->aliexpress_order_numbers,
            'aliexpress_placed_at' => $this->aliexpress_placed_at?->toIso8601String(),
            'aliexpress_logistics' => $this->aliexpress_logistics,

            // ── AliExpress purchase record ─────────────────────────
            'purchase_reference' => $this->purchase_reference,
            'supplier_name' => $this->supplier_name,
            'supplier_cost' => $this->supplier_cost,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'purchase_notes' => $this->purchase_notes,

            // ── Fulfillment milestones ─────────────────────────────
            'purchased_at' => $this->purchased_at?->toIso8601String(),
            'stock_received_at' => $this->stock_received_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
