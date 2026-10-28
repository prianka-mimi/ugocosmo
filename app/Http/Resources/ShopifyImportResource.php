<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShopifyImportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->shopify_store_id,
            'status' => $this->status,
            'total_products' => $this->total_products,
            'total_processed' => $this->total_processed ?? 0,
            'imported_count' => $this->imported_count,
            'updated_count' => $this->updated_count ?? 0,
            'skipped_count' => $this->skipped_count,
            'failed_count' => $this->failed_count,

            // ── Progress ─────────────────────────────────────────────
            'progress_percentage' => $this->progressPercentage(),
            'estimated_remaining' => $this->estimatedRemaining(),
            'estimated_completion_at' => $this->estimatedCompletionAt(),
            'success_count' => $this->successCount(),

            // ── Lock status ──────────────────────────────────────────
            'is_locked' => $this->isLocked(),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'locked_by' => $this->locked_by,

            // ── Lifecycle flags ──────────────────────────────────────
            'is_cancellable' => $this->isCancellable(),
            'is_resumable' => $this->isResumable(),
            'is_retryable' => $this->isRetryable(),

            // ── Resume / Retry chain ─────────────────────────────────
            'resumed_from_id' => $this->resumed_from_id,
            'retry_of_id' => $this->retry_of_id,
            'last_processed_product_id' => $this->last_processed_product_id,

            'error_message' => $this->error_message,
            'summary' => $this->summary,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),

            'logs' => ShopifyImportLogResource::collection($this->whenLoaded('logs')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
