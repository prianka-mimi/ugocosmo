<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class BasicInfoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'profile_image' => ImageManager::getImageUrl($this->avatar),
            'occupation' => $this->occupation,
            'dob' => $this->dob?->toDateString(),
            'marital_status' => $this->marital_status,
        ];
    }
}
