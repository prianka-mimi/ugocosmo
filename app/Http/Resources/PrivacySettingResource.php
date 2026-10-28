<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PrivacySettingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'age_visibility' => $this->age_visibility ?? 'private',
            'employment_history_visibility' => $this->employment_history_visibility ?? 'verified',
            'qualifications_visibility' => $this->qualifications_visibility ?? 'verified',
            'profile_visibility' => $this->profile_visibility ?? 'public',
            'show_online_status' => (bool) $this->show_online_status,
            'message_permission' => $this->message_permission ?? 'everyone',
        ];
    }
}
