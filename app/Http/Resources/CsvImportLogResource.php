<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CsvImportLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'row_number' => $this->row_number,
            'sku' => $this->sku,
            'product_title' => $this->product_title,
            'action' => $this->action,
            'reason' => $this->reason,
            'error_message' => $this->error_message,
            'details' => $this->details ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
