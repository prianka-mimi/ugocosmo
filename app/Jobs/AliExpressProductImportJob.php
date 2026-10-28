<?php

namespace App\Jobs;

use App\DTOs\AliExpress\AliExpressImportOptions;
use App\Enums\AliExpressImportStatus;
use App\Models\AliExpressAccount;
use App\Models\AliExpressImport;
use App\Services\AliExpressImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AliExpressProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 minutes

    public int $tries = 3;

    public int $backoff = 30;

    public int $maxExceptions = 3;

    public function __construct(
        protected int $accountId,
        protected int $importId,
        protected int $userId,
        protected array $productIds,
        protected AliExpressImportOptions $options,
    ) {
        $this->queue = 'default';
    }

    public function handle(AliExpressImportService $importService): void
    {
        $account = AliExpressAccount::find($this->accountId);
        if (! $account || ! $account->isActive()) {
            Log::error('AliExpressImportJob: account not found or inactive', ['account_id' => $this->accountId]);
            $this->failImport('AliExpress account not found or inactive.');

            return;
        }

        $import = AliExpressImport::find($this->importId);
        if (! $import) {
            Log::error('AliExpressImportJob: import record not found', ['import_id' => $this->importId]);

            return;
        }

        // A cancel may have landed while this job sat in the queue —
        // starting the run would silently resurrect a cancelled import
        // and import products the seller explicitly stopped.
        if ($import->status === AliExpressImportStatus::Cancelled->value) {
            Log::info('AliExpressImportJob: skipping import cancelled while queued', [
                'import_id' => $this->importId,
            ]);

            return;
        }

        $import->update([
            'status' => AliExpressImportStatus::Processing->value,
            'started_at' => $import->started_at ?? now(),
            'total_products' => count($this->productIds),
        ]);

        // Resume counters: on a retry (timeout / worker crash), the
        // product-level idempotency (unique index + unchanged skip) already
        // prevents duplicates — these counters just prevent double-counting.
        $imported = $import->imported_count ?? 0;
        $updated = $import->updated_count ?? 0;
        $skipped = $import->skipped_count ?? 0;
        $failed = $import->failed_count ?? 0;
        $processed = $import->total_processed ?? 0;
        $errors = [];

        foreach (array_chunk($this->productIds, 25) as $chunk) {
            // Respect cancellation between chunks.
            $import->refresh();
            if ($import->status === AliExpressImportStatus::Cancelled->value) {
                Log::info('AliExpressImportJob: import cancelled during processing', [
                    'import_id' => $this->importId,
                    'processed_so_far' => $processed,
                ]);

                return;
            }

            // NOTE: no DB transaction wraps the chunk. Each importProduct()
            // is individually atomic and idempotent; a transaction here would
            // span up to 25 × 30s AliExpress HTTP calls, holding connections
            // open for minutes at scale. Durability comes from the progress
            // updates + retry idempotency below.
            $chunkErrors = [];

            foreach ($chunk as $productId) {
                $result = $importService->importProduct($account, $import, (string) $productId, $this->options);

                $imported += $result['imported'] ? 1 : 0;
                $updated += $result['updated'] ? 1 : 0;
                $skipped += $result['skipped'] ? 1 : 0;
                $failed += $result['failed'] ? 1 : 0;

                if ($result['error']) {
                    $chunkErrors[] = "product_id:{$productId}, error:{$result['error']}";
                }
            }

            $processed += count($chunk);
            $errors = array_merge($errors, $chunkErrors);

            $import->update([
                'imported_count' => $imported,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'failed_count' => $failed,
                'total_processed' => $processed,
                'error_message' => $errors !== []
                    ? json_encode(array_slice($errors, 0, 100))
                    : $import->error_message,
            ]);
        }

        // Re-check cancellation immediately before finalizing: a cancel that
        // lands between the last refresh() and this update must not be
        // overwritten by a terminal status ("resurrected" import).
        $finalized = DB::transaction(function () use ($import, $errors, $imported, $updated, $failed) {
            $current = AliExpressImport::find($import->id);
            if (! $current || $current->status !== AliExpressImportStatus::Processing->value) {
                return false;
            }

            $finalStatus = AliExpressImportStatus::Completed->value;
            if ($failed > 0 && $imported === 0 && $updated === 0) {
                $finalStatus = AliExpressImportStatus::Failed->value;
            } elseif ($failed > 0) {
                $finalStatus = AliExpressImportStatus::Partial->value;
            }

            $current->update([
                'status' => $finalStatus,
                'completed_at' => now(),
                'errors' => $errors !== [] ? array_slice($errors, 0, 100) : null,
                'error_message' => $errors !== [] ? json_encode(array_slice($errors, 0, 100)) : null,
            ]);

            return true;
        });

        if (! $finalized) {
            Log::info('AliExpressImportJob: import no longer processing, final status not written', [
                'import_id' => $this->importId,
            ]);

            return;
        }

        $account->update(['last_sync_at' => now()]);

        Log::info('AliExpressImportJob completed', [
            'import_id' => $this->importId,
            'account_id' => $this->accountId,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $import = AliExpressImport::find($this->importId);
        if ($import) {
            $import->update([
                'status' => AliExpressImportStatus::Failed->value,
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
            Log::error("AliExpressImportJob [{$this->importId}] permanently failed: ".$exception->getMessage());
        }
    }

    protected function failImport(string $reason): void
    {
        $import = AliExpressImport::find($this->importId);
        if ($import) {
            $import->update([
                'status' => AliExpressImportStatus::Failed->value,
                'error_message' => $reason,
                'completed_at' => now(),
            ]);
        }
    }
}
