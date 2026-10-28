<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray($request): array
    {
        $sender = $this->sender;
        $senderData = null;

        if ($sender) {
            $senderUser = $sender->user;
            if ($senderUser && in_array($senderUser->role, ['admin', 'supervisor'])) {
                $senderData = [
                    'id' => 0,
                    'full_name' => 'Admin',
                    'avatar_url' => asset('img/default.png'),
                ];
            } else {
                $senderData = [
                    'id' => $sender->id,
                    'full_name' => $sender->full_name,
                    'avatar_url' => ImageManager::fileUrl($sender->avatar_url),
                ];
            }
        }

        return [
            'id' => $this->id,
            'sender' => $senderData,
            'content' => $this->content,
            'is_read' => (bool) $this->is_read,
            'is_mine' => (int) $this->sender_id === (int) auth()->guard('jwt')->user()?->profile?->id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
