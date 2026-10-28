<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'provider' => [
                'id' => $this->provider?->id,
                'full_name' => $this->provider?->full_name,
                'username' => $this->provider?->username,
                'avatar_url' => ImageManager::fileUrl($this->provider?->avatar_url),
                'is_verified' => (bool) $this->provider?->user?->is_verified,
            ],
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'price_type' => $this->price_type,
            'service_type' => $this->service_type,
            'category' => $this->whenLoaded('category', fn () => new ServiceCategoryResource($this->category)),
            'estimated_delivery_time' => $this->estimated_delivery_time,
            'is_insured' => (bool) $this->is_insured,
            'is_certified' => (bool) $this->is_certified,
            'image_url' => ImageManager::fileUrl($this->image_url),
            'images' => $this->images
                ? array_map(fn ($img) => ImageManager::fileUrl($img), $this->images)
                : [],
            'video_url' => ImageManager::fileUrl($this->video_url),
            'location' => $this->location,
            'delivery_location' => $this->delivery_location,
            'address' => $this->address,
            'country' => $this->country,
            'city' => $this->city,
            'service_area' => $this->service_area,
            'areas_of_coverage' => $this->areas_of_coverage ?? [],
            'is_active' => (bool) $this->is_active,
            'packages' => $this->whenLoaded('packages', fn () => $this->packages->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'description' => $p->description,
                'price' => $p->price,
            ])),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'rating' => isset($this->reviews_avg_rating) ? round((float) $this->reviews_avg_rating, 1) : ($this->reviews()->avg('rating') ? round((float) $this->reviews()->avg('rating'), 1) : 0.0),
            'rating_count' => isset($this->reviews_count) ? (int) $this->reviews_count : (int) $this->reviews()->count(),
            'is_saved' => auth('jwt')->user()?->profile?->id ? $this->saves()->where('user_id', auth('jwt')->user()->profile->id)->exists() : false,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
