<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray($request): array
    {
        $profileId = (int) auth()->guard('jwt')->user()?->profile?->id;

        $otherParticipant = (int) $this->participant_1 === $profileId
            ? $this->participantTwo
            : $this->participantOne;

        $otherParticipantData = null;
        if ($otherParticipant) {
            $otherUser = $otherParticipant->user;
            if ($otherUser && in_array($otherUser->role, ['admin', 'supervisor'])) {
                $otherParticipantData = [
                    'id' => 0,
                    'full_name' => 'Admin',
                    'username' => 'admin',
                    'avatar_url' => asset('img/default.png'),
                ];
            } else {
                $otherParticipantData = [
                    'id' => $otherParticipant->id,
                    'full_name' => $otherParticipant->full_name,
                    'username' => $otherParticipant->username,
                    'avatar_url' => ImageManager::fileUrl($otherParticipant->avatar_url),
                ];
            }
        }

        return [
            'id' => $this->id,
            'other_participant' => $otherParticipantData,
            'order_id' => $this->order_id,
            'message_type' => $this->message_type,
            'last_message' => $this->whenLoaded('latestMessage', fn () => [
                'content' => $this->latestMessage?->content,
                'sender_id' => $this->latestMessage?->sender_id,
                'created_at' => $this->latestMessage?->created_at?->toIso8601String(),
            ]),
            'unread_count' => $this->messages()
                ->unread()
                ->where('sender_id', '!=', $profileId)
                ->count(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
