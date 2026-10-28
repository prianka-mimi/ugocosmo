<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EscrowResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'amount' => (float) $this->amount,
            'payment_reference' => $this->payment_reference,
            'status' => $this->status,
            'buyer' => $this->whenLoaded('buyer', fn () => [
                'id' => $this->buyer?->id,
                'full_name' => $this->buyer?->full_name,
            ]),
            'seller' => $this->whenLoaded('seller', fn () => [
                'id' => $this->seller?->id,
                'full_name' => $this->seller?->full_name,
            ]),
            'payment_received_at' => $this->payment_received_at?->toIso8601String(),
            'escrow_released_at' => $this->escrow_released_at?->toIso8601String(),
            'payout_sent_at' => $this->payout_sent_at?->toIso8601String(),
            'disputes' => $this->whenLoaded('disputes'),
            'payouts' => $this->whenLoaded('payouts'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
