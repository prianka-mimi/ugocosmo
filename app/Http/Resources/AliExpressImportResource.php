<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AliExpressImportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->aliexpress_account_id,
            'status' => $this->status,
            'total_products' => $this->total_products,
            'total_processed' => $this->total_processed,
            'imported_count' => $this->imported_count,
            'updated_count' => $this->updated_count,
            'skipped_count' => $this->skipped_count,
            'failed_count' => $this->failed_count,
            'progress_percentage' => $this->progressPercentage(),
            'selected_product_ids' => $this->selected_product_ids ?? [],
            'filters' => $this->filters ?? [],
            'error_message' => $this->error_message,
            'errors' => $this->errors ?? [],
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'logs' => AliExpressImportLogResource::collection($this->whenLoaded('logs')),
        ];
    }
}
