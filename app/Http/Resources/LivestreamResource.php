<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class LivestreamResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'host' => [
                'id' => $this->host?->id,
                'full_name' => $this->host?->full_name,
                'username' => $this->host?->username,
                'avatar_url' => ImageManager::avatarUrl($this->host?->avatar_url),
            ],
            'title' => $this->title,
            'description' => $this->description,
            'thumbnail_url' => ImageManager::fileUrl($this->thumbnail_url),
            'is_live' => (bool) $this->is_live,
            'viewer_count' => $this->viewer_count,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
