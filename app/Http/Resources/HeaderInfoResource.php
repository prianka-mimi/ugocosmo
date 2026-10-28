<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class HeaderInfoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'bio' => $this->bio,
            'skills' => $this->skills ?? [],
            'interests' => $this->interests ?? [],
        ];
    }
}
