<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class JobApplicationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'job' => [
                'id' => $this->job?->id,
                'title' => $this->job?->title,
                'company' => $this->job?->company,
                'location' => $this->job?->location,
            ],
            'applicant' => [
                'id' => $this->applicant?->id,
                'full_name' => $this->applicant?->full_name,
                'username' => $this->applicant?->username,
                'avatar_url' => ImageManager::avatarUrl($this->applicant?->avatar_url),
            ],
            'status' => $this->status,
            'answers' => $this->answers,
            'is_qualified' => (bool) $this->is_qualified,
            'is_shortlisted' => (bool) $this->is_shortlisted,
            'cover_letter' => $this->cover_letter,
            'cv_resume' => ImageManager::fileUrl($this->cv_resume),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
