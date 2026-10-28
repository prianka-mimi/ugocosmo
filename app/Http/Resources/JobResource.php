<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class JobResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'poster' => [
                'id' => $this->poster?->id,
                'full_name' => $this->poster?->full_name,
                'username' => $this->poster?->username,
                'avatar_url' => ImageManager::avatarUrl($this->poster?->avatar_url),
                'is_verified' => (bool) $this->poster?->user?->is_verified,
            ],
            'title' => $this->title,
            'description' => $this->description,
            'company' => $this->company,
            'location' => $this->location,
            'salary_min' => $this->salary_min,
            'salary_max' => $this->salary_max,
            'job_type' => $this->job_type,
            'is_remote' => (bool) $this->is_remote,
            'is_active' => (bool) $this->is_active,
            'end_date' => $this->end_date?->toDateString(),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'qualifying_questions' => $this->whenLoaded('qualifyingQuestions', function () {
                return $this->qualifyingQuestions->map(function ($q) {
                    return [
                        'id' => $q->id,
                        'question' => $q->question,
                        'expected_answer' => $q->expected_answer,
                        'required' => (bool) $q->is_required,
                        'display_order' => $q->display_order,
                    ];
                });
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
