<?php

namespace App\Manager;

use App\Models\AdminAuditLog;
use App\Models\ShopifyImport;
use App\Models\ShopifyImportLog;
use App\Models\ShopifyStore;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * ShopifyImportAuditManager — Records every import lifecycle event.
 *
 * Tracks: import started, cancelled, resumed, retried, completed,
 * failed, locked, unlocked, and product-level actions.
 */
class ShopifyImportAuditManager
{
    /**
     * Log a high-level import lifecycle event to admin_audit_logs.
     */
    public function logLifecycle(
        string $action,
        ShopifyImport $import,
        ?User $user = null,
        ?array $extraData = null,
    ): void {
        try {
            AdminAuditLog::create([
                'admin_id' => $user?->id ?? ($import->user_id ?? 0),
                'action' => "shopify_import_{$action}",
                'entity_type' => 'shopify_import',
                'entity_id' => $import->id,
                'old_value' => null,
                'new_value' => array_merge([
                    'import_id' => $import->id,
                    'store_id' => $import->shopify_store_id,
                    'status' => $import->status,
                    'total_products' => $import->total_products,
                    'total_processed' => $import->total_processed,
                    'imported' => $import->imported_count,
                    'updated' => $import->updated_count,
                    'skipped' => $import->skipped_count,
                    'failed' => $import->failed_count,
                    'progress' => $import->progressPercentage(),
                ], $extraData ?? []),
                'ip_address' => request()->ip() ?? 'cli',
            ]);
        } catch (\Exception $e) {
            Log::error("ShopifyAudit: Failed to log lifecycle event {$action}: ".$e->getMessage());
        }
    }

    /**
     * Log that an import was started.
     */
    public function logStarted(ShopifyImport $import, ?User $user = null): void
    {
        $this->logLifecycle('started', $import, $user, [
            'selection_method' => $import->summary['selection_method'] ?? 'unknown',
            'has_collection_filter' => ! empty($import->summary['has_collection_filter']),
            'has_tag_filter' => ! empty($import->summary['has_tag_filter']),
        ]);
    }

    /**
     * Log that an import was cancelled.
     */
    public function logCancelled(ShopifyImport $import, ?User $user = null): void
    {
        $this->logLifecycle('cancelled', $import, $user, [
            'imported_before_cancel' => $import->imported_count,
            'total_processed_before_cancel' => $import->total_processed,
        ]);
    }

    /**
     * Log that an import was resumed.
     */
    public function logResumed(ShopifyImport $import, ?User $user = null): void
    {
        $this->logLifecycle('resumed', $import, $user, [
            'resumed_from_id' => $import->resumed_from_id,
            'previous_import_id' => $import->id,
            'previous_progress' => $import->progressPercentage(),
        ]);
    }

    /**
     * Log that an import was retried.
     */
    public function logRetried(ShopifyImport $import, ShopifyImport $retryOf, ?User $user = null): void
    {
        $this->logLifecycle('retried', $import, $user, [
            'retry_of_id' => $retryOf->id,
            'original_status' => $retryOf->status,
            'original_imported' => $retryOf->imported_count,
            'original_failed' => $retryOf->failed_count,
        ]);
    }

    /**
     * Log that an import completed.
     */
    public function logCompleted(ShopifyImport $import): void
    {
        $this->logLifecycle('completed', $import, null, [
            'duration_seconds' => $import->started_at
                ? $import->started_at->diffInSeconds($import->completed_at ?? now())
                : null,
            'final_status' => $import->status,
        ]);
    }

    /**
     * Log that an import was locked.
     */
    public function logLocked(ShopifyImport $import): void
    {
        $this->logLifecycle('locked', $import, null, [
            'locked_by' => $import->locked_by,
        ]);
    }

    /**
     * Log that an import was unlocked.
     */
    public function logUnlocked(ShopifyImport $import): void
    {
        $this->logLifecycle('unlocked', $import);
    }

    /**
     * Log a product-level event to shopify_import_logs.
     */
    public function logProductEvent(
        ShopifyImport $import,
        ShopifyStore $store,
        string $productId,
        string $action,
        ?string $reason = null,
        ?string $errorMessage = null,
        ?array $details = null,
    ): void {
        if (! $import->logs_enabled) {
            return;
        }

        try {
            ShopifyImportLog::create([
                'shopify_import_id' => $import->id,
                'shopify_store_id' => $store->id,
                'user_id' => $import->user_id,
                'shopify_product_id' => $productId,
                'action' => $action,
                'reason' => $reason,
                'error_message' => $errorMessage,
                'details' => $details,
            ]);
        } catch (\Exception $e) {
            Log::error("ShopifyAudit: Failed to log product event {$action} for {$productId}: ".$e->getMessage());
        }
    }

    /**
     * Log an error message to the import record.
     */
    public function appendError(ShopifyImport $import, string $errorMessage): void
    {
        try {
            $existing = $import->error_message ? json_decode($import->error_message, true) : [];
            $existing[] = $errorMessage;
            $import->update([
                'error_message' => json_encode(array_slice($existing, -100)), // keep last 100
            ]);
        } catch (\Exception $e) {
            Log::error('ShopifyAudit: Failed to append error: '.$e->getMessage());
        }
    }

    /**
     * Get audit logs for a specific import.
     */
    public function getAuditLogs(int $importId, int $limit = 50): array
    {
        return AdminAuditLog::where('entity_type', 'shopify_import')
            ->where('entity_id', $importId)
            ->recent()
            ->take($limit)
            ->get()
            ->toArray();
    }
}
