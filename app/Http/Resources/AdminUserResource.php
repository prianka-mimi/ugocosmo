<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AdminUserResource - Formats user data for the admin panel.
 *
 * Includes sensitive fields like status, verification, login info
 * that are only visible to admin/supervisor users.
 */
class AdminUserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'is_verified' => (bool) $this->is_verified,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'is_approved' => (bool) $this->is_approved,
            'account_type' => $this->account_type,
            'mobile_number' => $this->mobile_number,
            'profile' => $this->whenLoaded('profile', fn () => [
                'id' => $this->profile?->id,
                'full_name' => $this->profile?->full_name,
                'avatar_url' => ImageManager::avatarUrl($this->profile?->avatar_url),
                'is_seller' => (bool) $this->profile?->is_seller,
                'subscription_tier' => $this->profile?->subscription_tier,
            ]),
            'onboarding_completed' => (bool) $this->onboarding_completed,
            'terms_accepted' => (bool) $this->terms_accepted,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
