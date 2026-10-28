<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class StoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'creator' => [
                'id' => $this->creator?->id,
                'full_name' => $this->creator?->full_name,
                'username' => $this->creator?->username,
                'avatar_url' => ImageManager::fileUrl($this->creator?->avatar_url),
            ],
            'media_url' => ImageManager::fileUrl($this->media_url),
            'media_type' => $this->media_type,
            'caption' => $this->caption,
            'duration_seconds' => $this->duration_seconds,
            'view_count' => $this->view_count,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
