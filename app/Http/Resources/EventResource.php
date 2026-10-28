<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'organizer' => [
                'id' => $this->organizer?->id,
                'full_name' => $this->organizer?->full_name,
                'username' => $this->organizer?->username,
                'avatar_url' => ImageManager::avatarUrl($this->organizer?->avatar_url),
                'is_verified' => (bool) $this->organizer?->user?->is_verified,
            ],
            'title' => $this->title,
            'description' => $this->description,
            'country' => $this->country,
            'city' => $this->city,
            'location' => $this->location,
            'event_date' => $this->event_date?->toIso8601String(),
            'end_date' => $this->end_date?->toIso8601String(),
            'price' => $this->price,
            'currency' => $this->currency,
            'max_attendees' => $this->max_attendees,
            'ticket_prefix' => $this->ticket_prefix,
            'image_url' => ImageManager::fileUrl($this->image_url),
            'video_url' => ImageManager::fileUrl($this->video_url),
            'is_active' => (bool) $this->is_active,
            'is_published' => (bool) $this->is_published,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn ($section) => [
                'id' => $section->id,
                'name' => $section->section_name,
                'price' => $section->price,
                'seats' => $section->relationLoaded('seats')
                    ? $section->seats->map(fn ($seat) => [
                        'id' => $seat->id,
                        'seat_number' => $seat->seat_number,
                        'price' => $seat->price,
                        'status' => $seat->status,
                    ])
                    : [],
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'rating' => isset($this->reviews_avg_rating) ? round((float) $this->reviews_avg_rating, 1) : ($this->reviews()->avg('rating') ? round((float) $this->reviews()->avg('rating'), 1) : 0.0),
            'rating_count' => isset($this->reviews_count) ? (int) $this->reviews_count : (int) $this->reviews()->count(),
            'is_saved' => auth('jwt')->user()?->profile?->id ? $this->saves()->where('user_id', auth('jwt')->user()->profile->id)->exists() : false,
        ];
    }
}
