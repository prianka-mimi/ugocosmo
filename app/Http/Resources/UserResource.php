<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use App\Models\UserConnection;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        $connectionData = [
            'id' => null,
            'status' => 'none',
            'connection_type' => null,
        ];

        $currentUser = auth('jwt')->user();
        $currentProfileId = $currentUser?->profile?->id;
        $otherProfileId = $this->profile?->id;

        if ($currentProfileId && $otherProfileId && (int) $currentProfileId !== (int) $otherProfileId) {
            $conn = $this->connection_record ?? null;

            // Fallback in case they were not preloaded
            if (! $conn && ! property_exists($this->resource, 'connection_record')) {
                $conn = UserConnection::whereIn('connection_type', ['professional', 'social', 'friend'])
                    ->where(function ($q) use ($currentProfileId, $otherProfileId) {
                        $q->where('requester_id', $currentProfileId)->where('recipient_id', $otherProfileId);
                    })->orWhere(function ($q) use ($currentProfileId, $otherProfileId) {
                        $q->where('requester_id', $otherProfileId)->where('recipient_id', $currentProfileId);
                    })
                    ->first();
            }

            if ($conn) {
                if ($conn->status === 'accepted') {
                    $status = 'connected';
                } elseif ($conn->status === 'pending') {
                    $status = ((int) $conn->requester_id === (int) $currentProfileId) ? 'pending_sent' : 'pending_received';
                } else {
                    $status = 'none';
                }

                $connectionData = [
                    'id' => $conn->id,
                    'status' => $status,
                    'connection_type' => $conn->connection_type,
                ];
            }
        }

        return [
            'id' => $this->id,
            'account_type' => $this->account_type,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'avatar_url' => ImageManager::getImageUrl($this->avatar),
            'bio' => $this->bio ?? ($this->profile?->bio ?? null),
            'skills' => $this->skills ?? [],
            'is_verified' => (bool) $this->is_verified,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'connection' => $connectionData,
        ];
    }
}
