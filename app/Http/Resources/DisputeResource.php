<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DisputeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'escrow_payment_id' => $this->escrow_payment_id,
            'raised_by' => $this->whenLoaded('raisedBy', fn () => [
                'id' => $this->raisedBy?->id,
                'full_name' => $this->raisedBy?->full_name,
            ]),
            'reason' => $this->reason,
            'description' => $this->description,
            'evidence_urls' => $this->evidence_urls,
            'status' => $this->status,
            'resolution' => $this->resolution,
            'resolved_by' => $this->whenLoaded('resolvedBy', fn () => [
                'id' => $this->resolvedBy?->id,
                'full_name' => $this->resolvedBy?->full_name,
            ]),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'refund_amount' => (float) $this->refund_amount,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
