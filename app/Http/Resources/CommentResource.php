<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user?->id,
                'full_name' => $this->user?->full_name,
                'username' => $this->user?->username,
                'avatar_url' => ImageManager::avatarUrl($this->user?->avatar_url),
            ],
            'content' => $this->content,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
