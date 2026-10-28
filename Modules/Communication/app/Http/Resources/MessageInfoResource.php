<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageInfoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message_id' => (string) ($this->resource['message_id'] ?? ''),
            'read_by' => $this->resource['read_by'] ?? [],
            'delivered_to' => $this->resource['delivered_to'] ?? [],
        ];
    }
}
