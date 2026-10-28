<?php

namespace App\Http\Controllers;

use App\DTOs\Csv\CsvImportOptions;
use App\Exceptions\Csv\CsvImportException;
use App\Http\Requests\CsvImportRequest;
use App\Http\Resources\CsvImportResource;
use App\Http\Resources\ProductResource;
use App\Jobs\CsvProductImportJob;
use App\Models\CsvImport;
use App\Models\Product;
use App\Models\Profile;
use App\Repositories\CsvImportRepository;
use App\Services\CsvImportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class CsvImportController extends Controller
{
    public function __construct(
        protected CsvImportRepository $imports,
        protected CsvImportService $csvService,
    ) {}

    /**
     * Upload one or more CSVs and start a background product import.
     *
     * POST /api/csv/import
     * Body: { files: [<csv>, ...] } or { file: <csv> }, plus
     *       category_id?, default_condition?, default_currency?, download_images?, is_active?
     */
    public function import(CsvImportRequest $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return ApiResponse::error('Authentication required.', 401);
            }

            // The default guard is 'web'; authorize against the JWT user explicitly.
            if (! Gate::forUser($user)->allows('create', CsvImport::class)) {
                return ApiResponse::error('A seller profile is required to import products.', 403);
            }

            $profile = $user->profile;
            if (! $profile) {
                return ApiResponse::error('Profile not found. Complete your profile first.', 400);
            }

            $options = CsvImportOptions::fromRequest($request->validated());

            // Validate every selected file independently; invalid files are
            // reported but never block the valid ones.
            $stored = [];
            $rejected = [];
            $totalRows = 0;

            foreach ($this->uploadedFiles($request) as $file) {
                try {
                    $path = $this->csvService->storeUpload($file);
                    $this->csvService->validateStructure($path);

                    $rows = $this->csvService->countRows($path);
                    if ($rows === 0) {
                        throw new CsvImportException('The CSV file contains no data rows.');
                    }

                    $stored[] = [
                        'file' => $file,
                        'path' => $path,
                        'rows' => $rows,
                    ];
                    $totalRows += $rows;
                } catch (CsvImportException $e) {
                    // Keep a failed attempt per file: delete leftovers and record the reason.
                    $rejected[] = [
                        'file' => $file->getClientOriginalName(),
                        'reason' => $e->getMessage(),
                    ];
                    if (isset($path)) {
                        $this->csvService->deleteFile($path);
                    }
                }
            }

            if ($stored === []) {
                $reason = $rejected[0]['reason'] ?? 'No valid CSV files were provided.';

                return ApiResponse::error($reason, 422);
            }

            $import = $this->imports->create([
                'profile_id' => $profile->id,
                'user_id' => $user->id,
                'status' => 'pending',
                'original_filename' => count($stored) === 1 ? $stored[0]['file']->getClientOriginalName() : null,
                'file_path' => count($stored) === 1 ? $stored[0]['path'] : null,
                'total_rows' => $totalRows, // stored up-front; the job updates counts as it progresses
                'options' => $options->toArray(),
                'summary' => $rejected !== [] ? ['rejected_files' => $rejected] : null,
                'started_at' => now(),
            ]);

            foreach ($stored as $entry) {
                $this->imports->createFile([
                    'csv_import_id' => $import->id,
                    'original_filename' => $entry['file']->getClientOriginalName(),
                    'file_path' => $entry['path'],
                    'status' => 'pending',
                    'total_rows' => $entry['rows'],
                ]);
            }

            CsvProductImportJob::dispatch(
                importId: $import->id,
                userId: $user->id,
                sellerId: $profile->id,
                options: $options,
            );

            return ApiResponse::success([
                'import' => new CsvImportResource($import->load('files')),
                'rejected_files' => $rejected,
            ], 'Import started. The CSV files are being processed in the background.');
        } catch (Throwable $e) {
            Log::error('CSV import failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * The uploaded file(s): a single `file` field or a `files` array.
     *
     * @return array<int, UploadedFile>
     */
    protected function uploadedFiles(CsvImportRequest $request): array
    {
        if ($request->hasFile('files')) {
            return array_values($request->file('files', []));
        }

        if ($request->hasFile('file')) {
            return [$request->file('file')];
        }

        return [];
    }

    /**
     * Import history for the authenticated user.
     *
     * GET /api/csv/import/history?page=&per_page=
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            $perPage = min((int) $request->input('per_page', 20), 50);
            $imports = $this->imports->getByUser($user->id, $perPage);

            return ApiResponse::paginated($imports, CsvImportResource::collection($imports), 'Import history retrieved.');
        } catch (Throwable $e) {
            Log::error('CSV import history failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Import details with per-row logs.
     *
     * GET /api/csv/import/{importId}
     */
    public function detail(Request $request, int $importId): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            $import = $this->imports->findWithLogs($importId, $user->id);
            if (! $import) {
                return ApiResponse::error('Import record not found.', 404);
            }

            return ApiResponse::success([
                'import' => new CsvImportResource($import),
            ], 'Import details retrieved.');
        } catch (Throwable $e) {
            Log::error('CSV import detail failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Cancel a running import.
     *
     * POST /api/csv/import/{importId}/cancel
     */
    public function cancel(Request $request, int $importId): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            $import = $this->imports->findForUser($importId, $user->id);
            if (! $import) {
                return ApiResponse::error('Import record not found.', 404);
            }
            if (! $import->isCancellable()) {
                return ApiResponse::error("Import cannot be cancelled in its current state: {$import->status}.", 400);
            }

            $import->update(['status' => 'cancelled', 'completed_at' => now()]);

            return ApiResponse::success([
                'import' => new CsvImportResource($import->fresh()),
            ], 'Import cancelled.');
        } catch (Throwable $e) {
            Log::error('CSV import cancel failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * The seller's imported CSV marketplace listings.
     *
     * GET /api/csv/products?page=&per_page=
     */
    public function importedProducts(Request $request): JsonResponse
    {
        try {
            $profile = $this->profile();
            $perPage = min((int) $request->input('per_page', 20), 50);

            $products = Product::source('csv')
                ->bySeller($profile->id)
                ->with(['category', 'tags'])
                ->latestFirst()
                ->paginate($perPage);

            return ApiResponse::paginated($products, ProductResource::collection($products), 'Imported products retrieved.');
        } catch (Throwable $e) {
            Log::error('CSV imported products failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    protected function profile(): Profile
    {
        $user = Auth::guard('jwt')->user();
        if (! $user) {
            throw new \Exception('Authentication required.', 401);
        }
        $profile = $user->profile;
        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return $profile;
    }
}
