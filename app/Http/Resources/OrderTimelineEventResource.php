<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderTimelineEventResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->statusLabel() ?? $this->status,
            'actor_role' => $this->actor_role,
            'actor' => $this->whenLoaded('actor', fn () => [
                'id' => $this->actor->id,
                'full_name' => $this->actor->full_name,
                'username' => $this->actor->username,
            ]),
            'notes' => $this->notes,
            'metadata' => $this->metadata ?? [],
            'occurred_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
