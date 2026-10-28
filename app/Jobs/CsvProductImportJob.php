<?php

namespace App\Jobs;

use App\DTOs\Csv\CsvImportOptions;
use App\DTOs\Csv\CsvRowData;
use App\Enums\CsvImportStatus;
use App\Models\CsvImport;
use App\Models\CsvImportFile;
use App\Services\CsvImportService;
use App\Support\CsvReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes every file of a CSV import operation in the background.
 *
 * Each file is handled independently (own counts/status/errors) while the
 * parent CsvImport accumulates the aggregate statistics. One malformed or
 * failing file never prevents the remaining files from being processed.
 */
class CsvProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Runs on the default queue so any standard `php artisan queue:work`
     * (including the production supervisor config, which listens on
     * `shopify-imports,high,default`) processes CSV imports without
     * extra flags. A dedicated `csv-imports` queue was a silent failure
     * mode: workers not listening to it left imports stuck at `pending`.
     */
    public int $timeout = 900; // 15 minutes

    public int $tries = 3;

    public int $backoff = 30;

    public int $maxExceptions = 3;

    public const CHUNK_SIZE = 50;

    public function __construct(
        protected int $importId,
        protected int $userId,
        protected int $sellerId,
        protected CsvImportOptions $options,
    ) {}

    public function handle(CsvImportService $service): void
    {
        $import = CsvImport::find($this->importId);
        if (! $import) {
            Log::error('CsvImportJob: import record not found', ['import_id' => $this->importId]);

            return;
        }

        $files = $import->files;
        if ($files->isEmpty()) {
            $this->failImport($import, 'The import contains no files to process.');

            return;
        }

        $import->update([
            'status' => CsvImportStatus::Processing->value,
            'started_at' => $import->started_at ?? now(),
        ]);

        $summary = [
            'unmapped_columns' => [],
            'ignored_inventory_columns' => [],
        ];

        foreach ($files as $file) {
            if ($import->status === CsvImportStatus::Cancelled->value) {
                break;
            }

            $this->processFile($import, $file, $service, $summary);
        }

        $import->refresh();

        if ($import->status === CsvImportStatus::Cancelled->value) {
            $this->deleteFiles($files, $service);

            return;
        }

        $finalStatus = $this->finalStatus($import);
        $import->update([
            'status' => $finalStatus,
            'progress_percentage' => $import->progressPercentage(),
            'summary' => $summary,
            'completed_at' => now(),
        ]);

        $this->deleteFiles($files, $service);

        Log::info('CsvImportJob completed', [
            'import_id' => $this->importId,
            'user_id' => $this->userId,
            'total_rows' => $import->total_rows,
            'processed' => $import->processed_rows,
            'imported' => $import->imported_count,
            'updated' => $import->updated_count,
            'skipped' => $import->skipped_count,
            'failed' => $import->failed_count,
            'status' => $finalStatus,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $import = CsvImport::find($this->importId);
        if ($import) {
            $import->update([
                'status' => CsvImportStatus::Failed->value,
                'error_message' => 'Import failed unexpectedly. You can retry by uploading the file again.',
                'completed_at' => now(),
            ]);
        }

        $import?->files->each(fn (CsvImportFile $file) => $file->update([
            'status' => CsvImportStatus::Failed->value,
            'completed_at' => now(),
        ]));

        $import?->files->each(fn (CsvImportFile $file) => app(CsvImportService::class)->deleteFile($file->file_path));

        Log::error("CsvImportJob [{$this->importId}] permanently failed: ".$exception->getMessage());
    }

    // ─── Per-file processing ───────────────────────────────────────

    /**
     * Process one file: count rows, stream rows in chunks, update the
     * file's own counts/status and the import's aggregate counts.
     */
    protected function processFile(
        CsvImport $import,
        CsvImportFile $file,
        CsvImportService $service,
        array &$summary,
    ): void {
        if (! $service->fileExists($file->file_path)) {
            // Whole-file failure: every advertised row of this file counts
            // as failed so the aggregate status can never become "completed"
            // while a file did not actually import.
            $file->update([
                'status' => CsvImportStatus::Failed->value,
                'failed_count' => $file->total_rows,
                'error_message' => 'The uploaded CSV file is no longer available.',
                'completed_at' => now(),
            ]);
            $this->aggregateFromFile($import);

            return;
        }

        $file->update([
            'status' => CsvImportStatus::Processing->value,
            'started_at' => $file->started_at ?? now(),
        ]);

        try {
            $reader = new CsvReader($service->resolvePath($file->file_path));

            $summary['unmapped_columns'] = array_values(array_unique(
                array_merge($summary['unmapped_columns'], $reader->unmappedHeaders())
            ));
            if (isset($reader->headers()['quantity'])) {
                $summary['ignored_inventory_columns'][] = 'quantity';
            }

            $file->update([
                'total_rows' => $service->countRows($service->resolvePath($file->file_path)),
            ]);

            $fileErrors = [];

            $buffer = [];
            foreach ($reader->rows() as $index => $values) {
                $buffer[$index + 2] = new CsvRowData($values); // header is row 1; rows() is 0-based

                if (count($buffer) >= self::CHUNK_SIZE) {
                    $this->processChunk($import, $file, $service, $buffer, $fileErrors);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $this->processChunk($import, $file, $service, $buffer, $fileErrors);
            }

            $file->refresh();
            $file->update([
                'status' => $this->fileStatus($file),
                'errors' => $fileErrors !== [] ? array_slice($fileErrors, 0, 100) : null,
                'error_message' => $fileErrors !== [] ? $this->fileErrorMessage($fileErrors) : null,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('CsvImportJob: file processing failed', [
                'import_id' => $this->importId,
                'file' => $file->original_filename,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            $file->update([
                'status' => CsvImportStatus::Failed->value,
                'failed_count' => $file->total_rows,
                'error_message' => 'The CSV file could not be processed: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }

        $this->aggregateFromFile($import);
    }

    /**
     * Process one chunk of rows inside a single transaction. One invalid
     * row never aborts the chunk — it is recorded and processing continues.
     *
     * @param  array<int, CsvRowData>  $buffer  row number => row data
     * @param  array<int, array{row: int, reason: string}>  $fileErrors
     */
    protected function processChunk(
        CsvImport $import,
        CsvImportFile $file,
        CsvImportService $service,
        array $buffer,
        array &$fileErrors,
    ): void {
        // Respect cancellation between chunks.
        $import->refresh();
        if ($import->status === CsvImportStatus::Cancelled->value) {
            Log::info('CsvImportJob: import cancelled during processing', [
                'import_id' => $this->importId,
                'file' => $file->original_filename,
            ]);

            return;
        }

        $imageJobs = [];
        $chunkCounts = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        DB::transaction(function () use ($import, $file, $service, $buffer, &$fileErrors, &$imageJobs, &$chunkCounts) {
            foreach ($buffer as $rowNumber => $rowData) {
                $result = $service->processRow($import, $rowData, $rowNumber, $this->options, $this->sellerId, $file->original_filename);

                $chunkCounts['imported'] += $result['imported'] ? 1 : 0;
                $chunkCounts['updated'] += $result['updated'] ? 1 : 0;
                $chunkCounts['skipped'] += $result['skipped'] ? 1 : 0;
                $chunkCounts['failed'] += $result['failed'] ? 1 : 0;

                if ($result['error']) {
                    $fileErrors[] = [
                        'row' => $rowNumber,
                        'reason' => $result['error'],
                    ];
                }

                if (! empty($result['image_urls'])) {
                    $imageJobs[] = [
                        'product_id' => $result['product']->id,
                        'urls' => $result['image_urls'],
                        'main_url' => $result['image_urls'][0],
                    ];
                }
            }

            $file->incrementEach([
                'processed_rows' => count($buffer),
                'imported_count' => $chunkCounts['imported'],
                'updated_count' => $chunkCounts['updated'],
                'skipped_count' => $chunkCounts['skipped'],
                'failed_count' => $chunkCounts['failed'],
            ]);

            $import->incrementEach([
                'processed_rows' => count($buffer),
                'imported_count' => $chunkCounts['imported'],
                'updated_count' => $chunkCounts['updated'],
                'skipped_count' => $chunkCounts['skipped'],
                'failed_count' => $chunkCounts['failed'],
            ]);
        });

        // Dispatch image downloads only after the transaction committed so
        // the jobs can always load the persisted product.
        foreach ($imageJobs as $job) {
            CsvImageDownloadJob::dispatch(
                productId: $job['product_id'],
                urls: $job['urls'],
                mainUrl: $job['main_url'],
            );
        }
    }

    // ─── Status helpers ────────────────────────────────────────────

    protected function fileStatus(CsvImportFile $file): string
    {
        if ($file->failed_count > 0 && $file->imported_count === 0 && $file->updated_count === 0) {
            return CsvImportStatus::Failed->value;
        }

        if ($file->failed_count > 0) {
            return CsvImportStatus::Partial->value;
        }

        return CsvImportStatus::Completed->value;
    }

    protected function fileErrorMessage(array $fileErrors): string
    {
        $failed = count($fileErrors);

        return "{$failed} row(s) failed to import.".($fileErrors[0] ?? null ? " First error: {$fileErrors[0]['reason']}" : '');
    }

    /**
     * Recompute the import's aggregate counts from its files. Runs after
     * each file so the aggregate never depends on in-memory state across
     * queue retries.
     */
    protected function aggregateFromFile(CsvImport $import): void
    {
        $totals = $import->files()->get()->reduce(function (array $carry, CsvImportFile $file) {
            $carry['processed_rows'] += (int) $file->processed_rows;
            $carry['imported_count'] += (int) $file->imported_count;
            $carry['updated_count'] += (int) $file->updated_count;
            $carry['skipped_count'] += (int) $file->skipped_count;
            $carry['failed_count'] += (int) $file->failed_count;

            return $carry;
        }, [
            'processed_rows' => 0,
            'imported_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
        ]);

        $import->update([
            'processed_rows' => $totals['processed_rows'],
            'imported_count' => $totals['imported_count'],
            'updated_count' => $totals['updated_count'],
            'skipped_count' => $totals['skipped_count'],
            'failed_count' => $totals['failed_count'],
        ]);
    }

    protected function finalStatus(CsvImport $import): string
    {
        if ($import->failed_count > 0 && $import->imported_count === 0 && $import->updated_count === 0) {
            return CsvImportStatus::Failed->value;
        }

        if ($import->failed_count > 0) {
            return CsvImportStatus::Partial->value;
        }

        return CsvImportStatus::Completed->value;
    }

    protected function failImport(CsvImport $import, string $reason): void
    {
        $import->update([
            'status' => CsvImportStatus::Failed->value,
            'error_message' => $reason,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, CsvImportFile>  $files
     */
    protected function deleteFiles(Collection $files, CsvImportService $service): void
    {
        $files->each(fn (CsvImportFile $file) => $service->deleteFile($file->file_path));
    }
}
