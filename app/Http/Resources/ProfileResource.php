<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use App\Models\Post;
use App\Models\Profile;
use App\Models\UserConnection;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray($request): array
    {
        $profile = Profile::where('user_id', $this->id)->first();
        $profileId = $profile?->id;

        return [
            'cover_photo' => ImageManager::getImageUrl($this->cover_photo),
            'profile_photo' => ImageManager::getImageUrl($this->avatar),
            'name' => $this->name,
            'is_verified' => (bool) $this->is_verified,
            'username' => $this->username,
            'email' => $this->email,
            'mobile_number' => $this->mobile_number,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'title' => $this->occupation,
            'bio' => $this->bio,
            'location' => $profile?->location ?? ($this->city ? trim($this->city.($this->country ? ", {$this->country}" : '')) : null),
            'social_links' => SocialAccountResource::collection($this->whenLoaded('socialAccounts') ?? []),
            'join_date' => $this->created_at?->toIso8601String(),
            'total_posts' => $profileId ? Post::where('author_id', $profileId)->count() : 0,
            'total_followers' => $profileId ? UserConnection::where('recipient_id', $profileId)
                ->where('connection_type', 'follow')
                ->where('status', 'accepted')
                ->count() : 0,
            'total_following' => $profileId ? UserConnection::where('requester_id', $profileId)
                ->where('connection_type', 'follow')
                ->where('status', 'accepted')
                ->count() : 0,
        ];
    }
}
