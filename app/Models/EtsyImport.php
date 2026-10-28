<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EtsyImport extends Model
{
    use HasFactory;

    protected $table = 'etsy_imports';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'profile_id',
        'user_id',
        'status',
        'total_products',
        'total_processed',
        'imported_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'selected_listing_ids',
        'filters',
        'errors',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'total_products' => 'integer',
        'total_processed' => 'integer',
        'imported_count' => 'integer',
        'updated_count' => 'integer',
        'skipped_count' => 'integer',
        'failed_count' => 'integer',
        'selected_listing_ids' => 'array',
        'filters' => 'array',
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

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
        return $this->hasMany(EtsyImportLog::class, 'etsy_import_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByProfile($query, int $profileId)
    {
        return $query->where('profile_id', $profileId);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PROCESSING]);
    }

    // ─── Progress helpers ──────────────────────────────────────────

    public function progressPercentage(): float
    {
        if ($this->total_products <= 0) {
            return 0.0;
        }

        return round(($this->total_processed / $this->total_products) * 100, 1);
    }

    public function successCount(): int
    {
        return ($this->imported_count ?? 0) + ($this->updated_count ?? 0);
    }

    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }
}
