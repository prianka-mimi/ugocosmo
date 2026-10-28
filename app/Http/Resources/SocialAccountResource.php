<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SocialAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
