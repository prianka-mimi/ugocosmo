<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AliExpressImportLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'aliexpress_product_id' => $this->aliexpress_product_id,
            'product_title' => $this->product_title,
            'action' => $this->action,
            'reason' => $this->reason,
            'error_message' => $this->error_message,
            'details' => $this->details ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
