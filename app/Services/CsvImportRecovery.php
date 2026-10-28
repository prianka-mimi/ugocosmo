<?php

namespace App\Services;

use App\DTOs\Csv\CsvImportOptions;
use App\Enums\CsvImportStatus;
use App\Jobs\CsvProductImportJob;
use App\Models\CsvImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Self-healing watchdog for CSV product imports.
 *
 * A CSV import must never be stuck at `pending` forever: if the queued job
 * was lost (worker crashed mid-processing, queue connection reset, DB row
 * removed) or was dispatched to a queue no worker listens to (pre-fix
 * deployments that used the dedicated `csv-imports` queue), the import
 * record would otherwise sit silently with zero products. This service
 * re-dispatches the job so the import eventually completes.
 *
 * Re-dispatch is safe: the job is idempotent (rows match by
 * `(source='csv', seller_id, sku)` and update instead of duplicating, and
 * the DB unique index on `(seller_id, csv_sku)` is the final backstop).
 *
 * Scheduled every 5 minutes from routes/console.php
 * (`csv:recover-stuck-imports`).
 */
class CsvImportRecovery
{
    /**
     * Stale threshold: an import older than this with no queued job is lost.
     */
    private const STALE_MINUTES = 15;

    public function recover(): array
    {
        $stuck = CsvImport::query()
            ->whereIn('status', [
                CsvImportStatus::Pending->value,
                CsvImportStatus::Queued->value,
                CsvImportStatus::Processing->value,
            ])
            ->where('started_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->orderBy('id')
            ->get();

        $recovered = [];

        foreach ($stuck as $import) {
            if ($this->hasQueuedJob($import)) {
                continue; // a worker will pick it up — not lost
            }

            CsvProductImportJob::dispatch(
                importId: $import->id,
                userId: $import->user_id,
                sellerId: $import->profile_id,
                options: CsvImportOptions::fromRequest($import->options ?? []),
            );

            $recovered[] = $import->id;

            Log::warning('CsvImportRecovery: re-dispatched stuck import', [
                'import_id' => $import->id,
                'status' => $import->status,
                'stuck_since' => $import->started_at?->toIso8601String(),
            ]);
        }

        return $recovered;
    }

    /**
     * Is there still a queued CsvProductImportJob for this import?
     *
     * The job payload stores the protected constructor property as
     * `...importId";i:<id>;...` (JSON-escaped), so we pin on the exact
     * import id to avoid matching a different import that happens to share
     * a user/seller id.
     */
    protected function hasQueuedJob(CsvImport $import): bool
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%CsvProductImportJob%')
            ->where('payload', 'like', '%importId\\";i:'.$import->id.';%')
            ->exists();
    }
}
