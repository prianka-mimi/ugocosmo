<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class CvResumeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'cv_resume' => ImageManager::fileUrl($this->cv_resume),
        ];
    }
}
