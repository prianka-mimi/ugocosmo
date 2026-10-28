<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'balance' => (float) $this->balance,
            'total_deposited' => (float) $this->total_deposited,
            'total_withdrawn' => (float) $this->total_withdrawn,
            'total_gifted' => (float) $this->total_gifted,
            'total_received' => (float) $this->total_received,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'full_name' => $this->user?->full_name,
                'avatar_url' => ImageManager::fileUrl($this->user?->avatar_url),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
