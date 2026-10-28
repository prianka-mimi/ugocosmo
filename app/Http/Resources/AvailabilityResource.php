<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AvailabilityResource extends JsonResource
{
    public function toArray($request): array
    {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return [
            'id' => $this->id,
            'day_of_week' => $this->day_of_week,
            'day_name' => $days[$this->day_of_week] ?? 'Unknown',
            'start_time' => $this->start_time ? substr($this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr($this->end_time, 0, 5) : null,
            'is_available' => (bool) $this->is_available,
        ];
    }
}
