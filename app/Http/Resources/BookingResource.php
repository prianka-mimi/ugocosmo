<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'service' => $this->whenLoaded('service', fn () => [
                'id' => $this->service?->id,
                'title' => $this->service?->title,
                'price' => $this->service?->price,
                'price_type' => $this->service?->price_type,
                'image_url' => ImageManager::fileUrl($this->service?->image_url),
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer?->id,
                'full_name' => $this->customer?->full_name,
                'username' => $this->customer?->username,
                'avatar_url' => ImageManager::avatarUrl($this->customer?->avatar_url),
                'is_verified' => (bool) $this->customer?->user?->is_verified,
            ]),
            'provider' => $this->whenLoaded('provider', fn () => [
                'id' => $this->provider?->id,
                'full_name' => $this->provider?->full_name,
                'username' => $this->provider?->username,
                'avatar_url' => ImageManager::avatarUrl($this->provider?->avatar_url),
                'is_verified' => (bool) $this->provider?->user?->is_verified,
            ]),
            'booking_date' => $this->booking_date?->toDateString(),
            'booking_time' => $this->booking_time,
            'status' => $this->status,
            'notes' => $this->notes,
            'delivery_location' => $this->delivery_location,
            'customer_address' => $this->customer_address,
            'package_id' => $this->package_id,
            'package' => $this->whenLoaded('package', fn () => [
                'id' => $this->package?->id,
                'title' => $this->package?->title,
                'price' => (float) $this->package?->price,
            ]),
            'duration' => $this->duration,
            'images' => $this->images
                ? array_map(fn ($img) => ImageManager::fileUrl($img), $this->images)
                : [],
            'delivery_text' => $this->delivery_text,
            'delivery_files' => $this->delivery_files
                ? array_map(fn ($file) => ImageManager::fileUrl($file), $this->delivery_files)
                : [],
            'subtotal' => (float) $this->subtotal,
            'buyer_fee' => (float) $this->buyer_fee,
            'seller_fee' => (float) $this->seller_fee,
            'platform_fee_total' => (float) $this->platform_fee_total,
            'total' => (float) $this->total,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
