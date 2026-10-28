<?php

namespace App\Models;

use App\Enums\AliExpressImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AliExpressImport extends Model
{
    use HasFactory;

    protected $table = 'aliexpress_imports';

    protected $fillable = [
        'aliexpress_account_id',
        'profile_id',
        'user_id',
        'status',
        'total_products',
        'total_processed',
        'imported_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'selected_product_ids',
        'filters',
        'errors',
        'error_message',
        'summary',
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
        'selected_product_ids' => 'array',
        'filters' => 'array',
        'errors' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AliExpressAccount::class, 'aliexpress_account_id');
    }

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
        return $this->hasMany(AliExpressImportLog::class, 'aliexpress_import_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────

    public function scopeByAccount($query, int $accountId)
    {
        return $query->where('aliexpress_account_id', $accountId);
    }

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
            AliExpressImportStatus::Pending->value,
            AliExpressImportStatus::Queued->value,
            AliExpressImportStatus::Processing->value,
        ]);
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
        return in_array($this->status, [
            'staged',
            AliExpressImportStatus::Pending->value,
            AliExpressImportStatus::Queued->value,
            AliExpressImportStatus::Processing->value,
        ], true);
    }
}
