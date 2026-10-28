<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentIntentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'user_id' => $this->user_id,
            'payment_intent_id' => $this->payment_intent_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'payment_status' => $this->payment_status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
