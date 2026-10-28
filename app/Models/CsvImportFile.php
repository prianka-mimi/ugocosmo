<?php

namespace App\Models;

use App\Enums\CsvImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded CSV file within a CsvImport operation. Keeps per-file
 * counts/status so individual file failures stay identifiable while the
 * parent CsvImport holds the aggregate statistics.
 */
class CsvImportFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'csv_import_id',
        'original_filename',
        'file_path',
        'status',
        'total_rows',
        'processed_rows',
        'imported_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'errors',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'imported_count' => 'integer',
        'updated_count' => 'integer',
        'skipped_count' => 'integer',
        'failed_count' => 'integer',
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(CsvImport::class, 'csv_import_id');
    }

    public function progressPercentage(): float
    {
        if ($this->total_rows <= 0) {
            return 0.0;
        }

        return round(($this->processed_rows / $this->total_rows) * 100, 1);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [
            CsvImportStatus::Pending->value,
            CsvImportStatus::Queued->value,
            CsvImportStatus::Processing->value,
        ], true);
    }
}
