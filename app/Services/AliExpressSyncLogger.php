<?php

namespace App\Services;

use App\Models\AliExpressSyncLog;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around AliExpressSyncLog that provides a clean audit-trail
 * API for background sync operations (token refresh, category sync, etc.).
 *
 * Deliberately tolerant of database failures: logging errors must never
 * break the primary operation being audited.
 */
class AliExpressSyncLogger
{
    /**
     * Open a new sync log entry in the "started" state.
     */
    public function start(int $aliexpressAccountId, string $type): ?AliExpressSyncLog
    {
        try {
            return AliExpressSyncLog::create([
                'aliexpress_account_id' => $aliexpressAccountId,
                'type' => $type,
                'status' => AliExpressSyncLog::STATUS_STARTED,
                'started_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AliExpressSyncLogger::start failed', [
                'account_id' => $aliexpressAccountId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Mark an existing sync log entry as completed.
     */
    public function complete(?AliExpressSyncLog $log, string $message = ''): void
    {
        if (! $log) {
            return;
        }

        try {
            $log->update([
                'status' => AliExpressSyncLog::STATUS_COMPLETED,
                'message' => $message ?: null,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AliExpressSyncLogger::complete failed', [
                'log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark an existing sync log entry as failed.
     */
    public function fail(?AliExpressSyncLog $log, string $message = ''): void
    {
        if (! $log) {
            return;
        }

        try {
            $log->update([
                'status' => AliExpressSyncLog::STATUS_FAILED,
                'message' => $message ?: null,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AliExpressSyncLogger::fail failed', [
                'log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
