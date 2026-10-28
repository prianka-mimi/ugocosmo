<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BlockedDateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'blocked_date' => $this->blocked_date instanceof \DateTimeInterface
                ? $this->blocked_date->format('Y-m-d')
                : $this->blocked_date,
            'start_time' => $this->start_time ? substr($this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr($this->end_time, 0, 5) : null,
            'reason' => $this->reason,
        ];
    }
}
