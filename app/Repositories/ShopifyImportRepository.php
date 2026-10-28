<?php

namespace App\Repositories;

use App\Jobs\ShopifyProductImportJob;
use App\Manager\ShopifyImportAuditManager;
use App\Models\ShopifyImport;
use App\Models\ShopifyStore;
use Illuminate\Pagination\LengthAwarePaginator;

class ShopifyImportRepository
{
    /**
     * Get import history for a store with pagination.
     */
    public function getByStore(int $storeId, int $perPage = 20): LengthAwarePaginator
    {
        return ShopifyImport::where('shopify_store_id', $storeId)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get a single import record with its logs.
     */
    public function findWithLogs(int $id, int $storeId): ?ShopifyImport
    {
        return ShopifyImport::where('id', $id)
            ->where('shopify_store_id', $storeId)
            ->with(['logs' => fn ($q) => $q->latest()->take(500)])
            ->first();
    }

    /**
     * Find an import by ID (no store restriction).
     */
    public function findById(int $id): ?ShopifyImport
    {
        return ShopifyImport::find($id);
    }

    /**
     * Find an import with store and ownership validation.
     */
    public function findForUser(int $id, int $storeId, int $userId): ?ShopifyImport
    {
        return ShopifyImport::where('id', $id)
            ->where('shopify_store_id', $storeId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Create a new import record.
     */
    public function create(array $data): ShopifyImport
    {
        return ShopifyImport::create($data);
    }

    /**
     * Update import progress.
     */
    public function updateProgress(int $importId, array $data): ?ShopifyImport
    {
        $import = ShopifyImport::find($importId);
        if ($import) {
            $import->update($data);
        }

        return $import;
    }

    /**
     * Get the latest import for a store.
     */
    public function getLatest(int $storeId): ?ShopifyImport
    {
        return ShopifyImport::where('shopify_store_id', $storeId)
            ->latest()
            ->first();
    }

    /**
     * Get imports by status.
     */
    public function getByStatus(string $status, int $storeId): LengthAwarePaginator
    {
        return ShopifyImport::where('shopify_store_id', $storeId)
            ->where('status', $status)
            ->latest()
            ->paginate(20);
    }

    // ─── Cancel ────────────────────────────────────────────────────

    /**
     * Cancel a pending or in-progress import.
     *
     * Sets status to 'cancelled'. The running job checks this flag before
     * each chunk and stops processing.
     */
    public function cancel(ShopifyImport $import): ShopifyImport
    {
        $import->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);

        app(ShopifyImportAuditManager::class)->logCancelled($import);

        return $import->fresh();
    }

    // ─── Resume ────────────────────────────────────────────────────

    /**
     * Resume an interrupted import.
     *
     * Creates a new import record that inherits the original import's
     * configuration and resumes from the last processed product.
     */
    public function resume(ShopifyImport $import, int $userId): ?ShopifyImport
    {
        if (! $import->isResumable()) {
            return null;
        }

        // Ensure stale locks are released
        $import->releaseStaleLock();

        // Create a new import record carrying forward the original progress
        $resumed = $this->create([
            'shopify_store_id' => $import->shopify_store_id,
            'profile_id' => $import->profile_id,
            'user_id' => $userId,
            'status' => 'pending',
            'total_products' => $import->total_products ?? 0,
            'total_processed' => $import->total_processed ?? 0,
            'imported_count' => $import->imported_count ?? 0,
            'updated_count' => $import->updated_count ?? 0,
            'skipped_count' => $import->skipped_count ?? 0,
            'failed_count' => $import->failed_count ?? 0,
            'selected_product_ids' => $import->selected_product_ids,
            'last_processed_product_id' => $import->last_processed_product_id,
            'resumed_from_id' => $import->id,
            'logs_enabled' => $import->logs_enabled ?? true,
            'summary' => array_merge($import->summary ?? [], [
                'resumed' => true,
                'resumed_at' => now()->toIso8601String(),
                'original_import_id' => $import->id,
            ]),
            'started_at' => now(),
        ]);

        app(ShopifyImportAuditManager::class)->logResumed($resumed);

        return $resumed;
    }

    /**
     * Dispatch the resumed import job.
     */
    public function dispatchResume(ShopifyImport $resumed, ShopifyStore $store): void
    {
        $filterSummary = $resumed->summary ?? [];
        $lastProcessedId = $resumed->last_processed_product_id;
        $selectedIds = $resumed->selected_product_ids ?? [];

        ShopifyProductImportJob::dispatch(
            storeId: $store->id,
            importId: $resumed->id,
            userId: $resumed->user_id,
            productIds: $selectedIds,
            collectionIds: ! empty($filterSummary['has_collection_filter'])
                ? ($filterSummary['collection_ids'] ?? null) : null,
            tags: ! empty($filterSummary['has_tag_filter'])
                ? ($filterSummary['tags'] ?? null) : null,
            statusFilter: $filterSummary['status_filter'] ?? null,
            updatedSince: $filterSummary['updated_since'] ?? null,
            importAll: ($filterSummary['selection_method'] ?? null) === 'all',
            // Resume parameters
            isResume: true,
            resumeAfterProductId: $lastProcessedId,
        );
    }

    // ─── Retry ─────────────────────────────────────────────────────

    /**
     * Retry a failed or partial import.
     *
     * Creates a new import record linked to the failed one and dispatches
     * a fresh job. Products already successfully imported will be
     * skipped via composite dedup in the job.
     */
    public function retry(ShopifyImport $import, int $userId): ?ShopifyImport
    {
        if (! $import->isRetryable()) {
            return null;
        }

        // Release any stale locks
        $import->releaseStaleLock();

        // Find the store
        $store = ShopifyStore::find($import->shopify_store_id);
        if (! $store) {
            return null;
        }

        // Create a retry import record
        $retry = $this->create([
            'shopify_store_id' => $import->shopify_store_id,
            'profile_id' => $import->profile_id,
            'user_id' => $userId,
            'status' => 'pending',
            'total_products' => $import->total_products ?? 0,
            'total_processed' => 0,
            'imported_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'selected_product_ids' => $import->selected_product_ids,
            'retry_of_id' => $import->id,
            'logs_enabled' => $import->logs_enabled ?? true,
            'summary' => array_merge($import->summary ?? [], [
                'retry' => true,
                'retried_at' => now()->toIso8601String(),
                'original_import_id' => $import->id,
            ]),
            'started_at' => now(),
        ]);

        // Audit log
        app(ShopifyImportAuditManager::class)->logRetried($retry, $import);

        // Dispatch the retry job (no resume — restarts from scratch)
        $filterSummary = $import->summary ?? [];
        ShopifyProductImportJob::dispatch(
            storeId: $store->id,
            importId: $retry->id,
            userId: $userId,
            productIds: $import->selected_product_ids ?? [],
            collectionIds: ! empty($filterSummary['has_collection_filter'])
                ? ($filterSummary['collection_ids'] ?? null) : null,
            tags: ! empty($filterSummary['has_tag_filter'])
                ? ($filterSummary['tags'] ?? null) : null,
            statusFilter: $filterSummary['status_filter'] ?? null,
            updatedSince: $filterSummary['updated_since'] ?? null,
            importAll: ($filterSummary['selection_method'] ?? null) === 'all',
        );

        return $retry;
    }

    // ─── Progress ──────────────────────────────────────────────────

    /**
     * Get detailed progress information for an import.
     */
    public function getProgress(ShopifyImport $import): array
    {
        $import->loadMissing('logs');

        $recentLogs = $import->logs
            ->sortByDesc('created_at')
            ->take(20)
            ->values()
            ->toArray();

        // Count failed items
        $failedItems = $import->logs
            ->where('action', 'failed')
            ->take(100)
            ->values()
            ->map(fn ($log) => [
                'shopify_product_id' => $log->shopify_product_id,
                'error_message' => $log->error_message ?? $log->reason,
                'occurred_at' => $log->created_at?->toIso8601String(),
            ]);

        return [
            'id' => $import->id,
            'store_id' => $import->shopify_store_id,
            'status' => $import->status,
            'total_products' => $import->total_products,
            'total_processed' => $import->total_processed,
            'imported_count' => $import->imported_count,
            'updated_count' => $import->updated_count,
            'skipped_count' => $import->skipped_count,
            'failed_count' => $import->failed_count,
            'progress_percentage' => $import->progressPercentage(),
            'estimated_remaining' => $import->estimatedRemaining(),
            'estimated_completion_at' => $import->estimatedCompletionAt(),
            'is_locked' => $import->isLocked(),
            'locked_at' => $import->locked_at?->toIso8601String(),
            'is_cancellable' => $import->isCancellable(),
            'is_resumable' => $import->isResumable(),
            'is_retryable' => $import->isRetryable(),
            'started_at' => $import->started_at?->toIso8601String(),
            'completed_at' => $import->completed_at?->toIso8601String(),
            'recent_logs' => $recentLogs,
            'failed_items' => $failedItems,
        ];
    }

    // ─── Lock Management ───────────────────────────────────────────

    /**
     * Release stale locks on all imports for a store.
     */
    public function releaseStaleLocks(int $storeId, int $maxLockMinutes = 30): int
    {
        $released = 0;

        $lockedImports = ShopifyImport::where('shopify_store_id', $storeId)
            ->whereNotNull('locked_at')
            ->get();

        foreach ($lockedImports as $import) {
            if ($import->releaseStaleLock($maxLockMinutes)) {
                $released++;
            }
        }

        return $released;
    }

    /**
     * Get the most recent active import for a store.
     */
    public function getActive(int $storeId): ?ShopifyImport
    {
        return ShopifyImport::where('shopify_store_id', $storeId)
            ->whereIn('status', ['pending', 'queued', 'processing'])
            ->latest()
            ->first();
    }

    /**
     * Check if a store has an active (non-completed, non-cancelled) import.
     */
    public function hasActive(int $storeId): bool
    {
        return ShopifyImport::where('shopify_store_id', $storeId)
            ->whereIn('status', ['pending', 'queued', 'processing'])
            ->exists();
    }
}
