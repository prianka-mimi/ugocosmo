<?php

namespace App\Models;

use App\Enums\CsvImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsvImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'user_id',
        'status',
        'original_filename',
        'file_path',
        'total_rows',
        'processed_rows',
        'imported_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'options',
        'errors',
        'error_message',
        'summary',
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
        'progress_percentage' => 'float',
        'options' => 'array',
        'errors' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ─── Relationships ─────────────────────────────────────────────

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CsvImportLog::class, 'csv_import_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CsvImportFile::class, 'csv_import_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            CsvImportStatus::Pending->value,
            CsvImportStatus::Queued->value,
            CsvImportStatus::Processing->value,
        ]);
    }

    // ─── Progress helpers ──────────────────────────────────────────

    public function progressPercentage(): float
    {
        if ($this->total_rows <= 0) {
            return 0.0;
        }

        return min(round(($this->processed_rows / $this->total_rows) * 100, 1), 100.0);
    }

    public function successCount(): int
    {
        return ($this->imported_count ?? 0) + ($this->updated_count ?? 0);
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
