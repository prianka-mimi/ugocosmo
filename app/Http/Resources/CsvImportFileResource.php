<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CsvImportFileResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'imported_count' => $this->imported_count,
            'updated_count' => $this->updated_count,
            'skipped_count' => $this->skipped_count,
            'failed_count' => $this->failed_count,
            'progress_percentage' => $this->progressPercentage(),
            'errors' => $this->errors ?? [],
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
