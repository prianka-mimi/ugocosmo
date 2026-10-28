<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ShopifyImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_store_id',
        'profile_id',
        'user_id',
        'status',
        'total_products',
        'total_processed',
        'imported_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'error_message',
        'selected_product_ids',
        'last_processed_product_id',
        'resumed_from_id',
        'retry_of_id',
        'locked_at',
        'locked_by',
        'logs_enabled',
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
        'summary' => 'array',
        'logs_enabled' => 'boolean',
        'locked_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ─── Relationships ─────────────────────────────────────────────

    public function store(): BelongsTo
    {
        return $this->belongsTo(ShopifyStore::class, 'shopify_store_id');
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
        return $this->hasMany(ShopifyImportLog::class, 'shopify_import_id');
    }

    public function resumedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resumed_from_id');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function resumedImports(): HasMany
    {
        return $this->hasMany(self::class, 'resumed_from_id');
    }

    public function retryImports(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByStore($query, int $storeId)
    {
        return $query->where('shopify_store_id', $storeId);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: imports that are locked (actively processing).
     */
    public function scopeLocked($query)
    {
        return $query->whereNotNull('locked_at');
    }

    /**
     * Scope: imports that are unlocked (not actively processing).
     */
    public function scopeUnlocked($query)
    {
        return $query->whereNull('locked_at');
    }

    /**
     * Scope: imports that can be resumed (interrupted with partial progress).
     */
    public function scopeResumable($query)
    {
        return $query->whereIn('status', ['processing', 'partial', 'failed'])
            ->whereNull('locked_at')
            ->where('total_processed', '>', 0);
    }

    /**
     * Scope: imports that can be retried (failed or partial with no current retry).
     */
    public function scopeRetryable($query)
    {
        return $query->whereIn('status', ['failed', 'partial'])
            ->whereNull('locked_at')
            ->whereDoesntHave('retryImports');
    }

    // ─── Progress Helpers ──────────────────────────────────────────

    /**
     * Calculate the progress percentage (0–100).
     */
    public function progressPercentage(): float
    {
        if ($this->total_products <= 0) {
            return 0.0;
        }
        $processed = $this->total_processed;

        return round(($processed / max($this->total_products, 1)) * 100, 1);
    }

    /**
     * Estimate remaining items to process.
     */
    public function estimatedRemaining(): int
    {
        return max(0, ($this->total_products ?? 0) - ($this->total_processed ?? 0));
    }

    /**
     * Estimate the completion time based on processing rate.
     * Returns null if insufficient data to estimate.
     */
    public function estimatedCompletionAt(): ?string
    {
        if ($this->total_processed <= 3 || ! $this->started_at) {
            return null;
        }

        $elapsedSeconds = max(1, $this->started_at->diffInRealSeconds(now()));
        $processingRate = $this->total_processed / $elapsedSeconds; // per second

        $remaining = $this->estimatedRemaining();
        if ($remaining <= 0 || $processingRate <= 0) {
            return null;
        }

        $estimatedSeconds = $remaining / $processingRate;

        return now()->addSeconds((int) $estimatedSeconds)->toIso8601String();
    }

    /**
     * Get the total successfully processed items (imported + updated).
     */
    public function successCount(): int
    {
        return ($this->imported_count ?? 0) + ($this->updated_count ?? 0);
    }

    /**
     * Check if this import is locked.
     */
    public function isLocked(): bool
    {
        return ! is_null($this->locked_at);
    }

    /**
     * Check if this import can be cancelled.
     */
    public function isCancellable(): bool
    {
        return in_array($this->status, ['pending', 'processing', 'queued'])
            && ! in_array($this->status, ['completed', 'cancelled']);
    }

    /**
     * Check if this import can be resumed.
     */
    public function isResumable(): bool
    {
        return in_array($this->status, ['processing', 'partial', 'failed', 'cancelled'])
            && ! $this->isLocked()
            && ($this->total_processed ?? 0) > 0;
    }

    /**
     * Check if this import can be retried.
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, ['failed', 'partial'])
            && ! $this->isLocked()
            && ! $this->retryImports()->exists();
    }

    // ─── Locking Helpers ───────────────────────────────────────────

    /**
     * Attempt to acquire a lock for this import.
     * Uses a database transaction to ensure atomicity.
     */
    public function acquireLock(string $lockIdentifier): bool
    {
        return DB::transaction(function () use ($lockIdentifier) {
            $fresh = self::where('id', $this->id)
                ->whereNull('locked_at')
                ->lockForUpdate()
                ->first();

            if (! $fresh) {
                return false;
            }

            $this->update([
                'locked_at' => now(),
                'locked_by' => $lockIdentifier,
            ]);

            return true;
        });
    }

    /**
     * Release the lock on this import.
     */
    public function releaseLock(): void
    {
        $this->update([
            'locked_at' => null,
            'locked_by' => null,
        ]);
    }

    /**
     * Release a stale lock (for recovery).
     */
    public function releaseStaleLock(int $maxLockMinutes = 30): bool
    {
        if (! $this->isLocked()) {
            return false;
        }

        if ($this->locked_at && $this->locked_at->diffInMinutes(now()) >= $maxLockMinutes) {
            $this->releaseLock();

            return true;
        }

        return false;
    }
}
