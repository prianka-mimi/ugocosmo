<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'buyer' => $this->whenLoaded('buyer', fn () => [
                'id' => $this->buyer?->id,
                'full_name' => $this->buyer?->full_name,
                'username' => $this->buyer?->username,
                'avatar_url' => ImageManager::avatarUrl($this->buyer?->avatar_url),
            ]),
            'seller' => $this->whenLoaded('seller', fn () => [
                'id' => $this->seller?->id,
                'full_name' => $this->seller?->full_name,
                'username' => $this->seller?->username,
                'avatar_url' => ImageManager::avatarUrl($this->seller?->avatar_url),
            ]),
            'status' => $this->status,
            'subtotal' => (float) $this->subtotal,
            'sub_total' => (float) $this->subtotal,
            'buyer_fee' => (float) $this->buyer_fee,
            'seller_fee' => (float) $this->seller_fee,
            'platform_fee_total' => (float) $this->platform_fee_total,
            'tax_amount' => (float) $this->tax_amount,
            'total' => (float) $this->total,
            'total_amount' => (float) $this->total,
            'shipping_address' => $this->shipping_address,
            'shipping_method' => $this->shipping_method,
            'shipping_amount' => $this->shipping_amount !== null ? (float) $this->shipping_amount : 0.00,
            'shipping_fee' => $this->shipping_amount !== null ? (float) $this->shipping_amount : 0.00,
            'shipping_total' => $this->shipping_amount !== null ? (float) $this->shipping_amount : 0.00,
            'selected_rate' => $this->when(
                $this->selected_rate_id !== null,
                fn () => [
                    'rate_id' => $this->selected_rate_id,
                    'carrier_code' => $this->selected_carrier_code,
                    'service_code' => $this->selected_service_code,
                    'service_name' => $this->selected_service_name,
                    'amount' => (float) $this->shipping_amount,
                    'currency' => $this->shipping_currency,
                ]
            ),
            'tracking_number' => $this->carrier_tracking_number,
            'transaction_number' => $this->transaction_number,
            'stripe_payment_intent_id' => $this->stripe_payment_intent_id,
            'aliexpress_order_numbers' => $this->aliexpress_order_numbers,
            'aliexpress_placed_at' => $this->aliexpress_placed_at?->toIso8601String(),
            'purchase_reference' => $this->purchase_reference,
            'supplier_name' => $this->supplier_name,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($item) {
                $product = $item->product;
                $service = $item->service;
                $event = $item->event;
                $source = $product?->source ?? ($service ? 'service' : ($event ? 'event' : 'local'));
                $itemImg = $item->image_url ?: ($product?->image_url ?? ($service?->image_url ?? $event?->image_url));

                return [
                    'id' => $item->id,
                    'item_type' => $item->item_type ?? ($product ? 'product' : ($service ? 'service' : ($event ? 'ticket' : 'item'))),
                    'product_id' => $item->product_id,
                    'service_id' => $item->service_id,
                    'event_id' => $item->event_id,
                    'booking_id' => $item->booking_id,
                    'title' => $item->title,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'total_price' => (float) $item->total_price,
                    'source' => $source,
                    'image_url' => ImageManager::fileUrl($itemImg),
                    'product' => $product ? [
                        'id' => $product->id,
                        'title' => $product->title,
                        'source' => $product->source ?? 'local',
                        'sku' => $product->sku,
                        'image_url' => ImageManager::fileUrl($product->image_url),
                        'aliexpress_product_id' => $product->aliexpress_product_id,
                        'etsy_listing_id' => $product->etsy_listing_id,
                    ] : null,
                ];
            })),
            'transaction' => $this->whenLoaded('transaction', fn () => [
                'id' => $this->transaction?->id,
                'amount' => (float) $this->transaction?->amount,
                'status' => $this->transaction?->status,
                'stripe_payment_id' => $this->transaction?->stripe_payment_id,
            ]),
            'return_details' => $this->when(
                in_array($this->status, ['refund_requested', 'refund_approved', 'refund_rejected', 'return_shipped', 'refunded'], true),
                function () {
                    $events = $this->relationLoaded('timelineEvents') ? $this->timelineEvents : $this->timelineEvents()->get();
                    $lastRefundReq = $events->where('status', 'refund_requested')->last();
                    $lastReturnShip = $events->where('status', 'return_shipped')->last();

                    return [
                        'status' => $this->status,
                        'reason' => $lastRefundReq?->metadata['reason'] ?? $lastRefundReq?->notes,
                        'notes' => $lastRefundReq?->metadata['notes'] ?? null,
                        'refund_amount' => $lastRefundReq?->metadata['refund_amount'] ?? (float) $this->total,
                        'product_id' => $lastRefundReq?->metadata['product_id'] ?? null,
                        'order_item_id' => $lastRefundReq?->metadata['order_item_id'] ?? null,
                        'return_tracking_number' => $lastReturnShip?->metadata['return_tracking_number'] ?? null,
                        'return_courier' => $lastReturnShip?->metadata['return_courier'] ?? null,
                        'requested_at' => $lastRefundReq?->created_at?->toIso8601String(),
                    ];
                }
            ),
            'timeline' => OrderTimelineEventResource::collection($this->whenLoaded('timelineEvents')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
