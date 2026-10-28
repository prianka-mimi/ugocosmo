<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CsvImportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'original_filename' => $this->original_filename,
            'file_count' => $this->whenLoaded('files', fn () => $this->files->count()),
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'imported_count' => $this->imported_count,
            'updated_count' => $this->updated_count,
            'skipped_count' => $this->skipped_count,
            'failed_count' => $this->failed_count,
            'progress_percentage' => $this->progressPercentage(),
            'options' => $this->options ?? [],
            'summary' => $this->summary ?? [],
            'error_message' => $this->error_message,
            'errors' => $this->errors ?? [],
            'files' => CsvImportFileResource::collection($this->whenLoaded('files')),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'logs' => CsvImportLogResource::collection($this->whenLoaded('logs')),
        ];
    }
}
