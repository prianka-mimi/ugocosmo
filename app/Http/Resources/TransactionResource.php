<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'balance_after' => (float) $this->balance_after,
            'reference' => $this->reference,
            'description' => $this->description,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'recipient' => $this->whenLoaded('recipient', fn () => [
                'id' => $this->recipient?->id,
                'full_name' => $this->recipient?->full_name,
            ]),
            'livestream_id' => $this->livestream_id,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
