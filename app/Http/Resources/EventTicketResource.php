<?php

namespace App\Http\Resources;

use App\Manager\ImageManager;
use Illuminate\Http\Resources\Json\JsonResource;

class EventTicketResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->whenLoaded('event', fn () => [
                'id' => $this->event?->id,
                'title' => $this->event?->title,
                'event_date' => $this->event?->event_date?->toIso8601String(),
                'location' => $this->event?->location,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer?->id,
                'full_name' => $this->customer?->full_name,
                'username' => $this->customer?->username,
                'avatar_url' => ImageManager::avatarUrl($this->customer?->avatar_url),
            ]),
            'ticket_number' => $this->ticket_number,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_dob' => $this->customer_dob instanceof \DateTimeInterface
                ? $this->customer_dob->format('Y-m-d')
                : $this->customer_dob,
            'customer_address' => $this->customer_address,
            'status' => $this->status,
            'section' => $this->whenLoaded('section', fn () => [
                'id' => $this->section?->id,
                'section_name' => $this->section?->section_name,
                'price' => $this->section?->price,
            ]),
            'seat' => $this->whenLoaded('seat', fn () => [
                'id' => $this->seat?->id,
                'seat_number' => $this->seat?->seat_number,
                'price' => $this->seat?->price,
            ]),
            'purchased_at' => $this->purchased_at?->toIso8601String(),
            'used_at' => $this->used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
