<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class GiftResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'sender' => [
                'id' => $this->sender?->id,
                'full_name' => $this->sender?->full_name,
                'username' => $this->sender?->username,
                'avatar_url' => ImageManager::avatarUrl($this->sender?->avatar_url),
            ],
            'gift_type' => $this->gift_type,
            'amount' => (float) $this->amount,
            'message' => $this->message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
