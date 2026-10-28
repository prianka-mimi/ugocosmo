<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'reviewer' => [
                'id' => $this->reviewer?->id,
                'full_name' => $this->reviewer?->full_name,
                'username' => $this->reviewer?->username,
                'avatar_url' => ImageManager::avatarUrl($this->reviewer?->avatar_url),
            ],
            'rating' => $this->rating,
            'review_text' => $this->review_text,
            'is_mine' => (int) $this->reviewer_id === (int) auth()->guard('jwt')->user()?->profile?->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
