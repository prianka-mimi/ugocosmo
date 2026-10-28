<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShopifyImportLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'import_id' => $this->shopify_import_id,
            'shopify_product_id' => $this->shopify_product_id,
            'product_title' => $this->product_title,
            'action' => $this->action,
            'reason' => $this->reason,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
