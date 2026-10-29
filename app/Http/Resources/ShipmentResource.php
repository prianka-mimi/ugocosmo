<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe shipment output. Never includes ShipEngine API keys, carrier
 * account credentials, or raw external payloads.
 */
class ShipmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'seller_id' => $this->seller_id,
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order?->id,
                'status' => $this->order?->status,
                'transection_number' => $this->order?->transection_number,
                'transaction_number' => $this->order?->transaction_number,
                'tracking_number' => $this->order?->carrier_tracking_number ?? $this->tracking_number,
            ]),
            'rate_id' => $this->rate_id,
            'status' => $this->status,
            'label_status' => $this->label_status,
            'external_shipment_id' => $this->external_shipment_id,
            'external_label_id' => $this->external_label_id,
            'carrier_id' => $this->carrier_id,
            'carrier_code' => $this->carrier_code,
            'service_code' => $this->service_code,
            'tracking_number' => $this->tracking_number,
            'tracking_status' => $this->tracking_status,
            'shipping_cost' => $this->shipping_cost !== null ? (float) $this->shipping_cost : null,
            'currency' => $this->currency,
            'ship_from' => $this->whenLoaded('order', fn () => $this->ship_from_snapshot),
            'ship_to' => $this->whenLoaded('order', fn () => $this->ship_to_snapshot),
            'ship_date' => $this->ship_date?->toDateString(),
            'estimated_delivery_date' => $this->estimated_delivery_date?->toDateString(),
            'label_format' => $this->label_format,
            'label_download_url' => url('/api/shipping/download-label') . '?label_id=' . $this->id . '&download=1',
            'label_preview_url'  => url('/api/shipping/download-label') . '?label_id=' . $this->id . '&preview=1',
            'voided' => (bool) $this->voided,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'label_created_at' => $this->label_created_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
