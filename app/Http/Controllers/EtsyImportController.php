<?php

namespace App\Http\Controllers;

use App\DTOs\Etsy\EtsyImportOptions;
use App\Http\Requests\EtsyImportRequest;
use App\Http\Resources\EtsyImportResource;
use App\Models\EtsyAccount;
use App\Models\EtsyImport;
use App\Models\Product;
use App\Repositories\EtsyImportRepository;
use App\Services\EtsyImportService;
use App\Support\ApiResponse;
use App\Traits\ResolvesAuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Etsy import endpoints: import selected listings, history, detail,
 * cancel and imported-products list. Mirrors AliExpressImportController.
 */
class EtsyImportController extends Controller
{
    use ResolvesAuthUser;

    public function __construct(
        protected EtsyImportRepository $imports,
        protected EtsyImportService $importService,
    ) {}

    /**
     * Import Etsy Products
     */
    public function import(EtsyImportRequest $request): JsonResponse
    {
        try {
            $user = $this->user();
            $profile = $this->profile();

            // Same-to-same with AliExpress: the import runs through the
            // seller's connected Etsy account, and every imported product /
            // import run is linked to it (products.etsy_account_id).
            $account = EtsyAccount::byUser($user->id)->active()->latest('connected_at')->first();
            if (! $account) {
                return ApiResponse::error('No connected Etsy account found. Connect your Etsy account first.', 422);
            }

            $listingIds = $request->resolvedListingIds();

            if ($listingIds === []) {
                return ApiResponse::error('No valid Etsy listing IDs or URLs provided.', 422);
            }

            $alreadyImported = Product::query()
                ->where('source', 'etsy')
                ->where('seller_id', $profile->id)
                ->whereIn('etsy_listing_id', array_map('strval', $listingIds))
                ->pluck('etsy_listing_id')
                ->all();

            $newIds = array_values(array_diff($listingIds, array_map('intval', $alreadyImported)));

            if ($newIds === []) {
                return ApiResponse::success([
                    'imported' => 0,
                    'skipped' => count($listingIds),
                    'failed' => 0,
                    'skipped_listing_ids' => $listingIds,
                ], 'All listing(s) are already imported.');
            }

            $options = EtsyImportOptions::fromRequest($request->validated());

            $import = $this->imports->create([
                'profile_id' => $profile->id,
                'user_id' => $user->id,
                'etsy_account_id' => $account->id,
                'status' => EtsyImport::STATUS_PROCESSING,
                'total_products' => count($newIds),
                'total_processed' => 0,
                'imported_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'failed_count' => 0,
                'selected_listing_ids' => $newIds,
                'filters' => $options->toArray(),
                'started_at' => now(),
            ]);

            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $failed = 0;
            $errors = [];
            $products = [];

            // Per-listing failures are contained by importListing(); the
            // run-level counter updates happen after the loop, so a partial
            // failure never leaves a half-bumped run record.
            foreach ($newIds as $listingId) {
                $result = $this->importService->importListing($profile->id, $listingId, $options, $account);
                $imported += $result['imported'] ? 1 : 0;
                $updated += $result['updated'] ? 1 : 0;
                $skipped += $result['skipped'] ? 1 : 0;
                $failed += $result['failed'] ? 1 : 0;
                if ($result['error']) {
                    $errors[] = $result['error'];
                }
                if ($result['product']) {
                    $products[] = $this->toEtsyFormat($result['product']);
                }
            }

            $finalStatus = EtsyImport::STATUS_COMPLETED;
            if ($failed > 0 && $imported === 0 && $updated === 0) {
                $finalStatus = EtsyImport::STATUS_FAILED;
            } elseif ($failed > 0) {
                $finalStatus = EtsyImport::STATUS_PARTIAL;
            }

            $import->update([
                'status' => $finalStatus,
                'total_processed' => count($newIds),
                'imported_count' => $imported,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'failed_count' => $failed,
                'completed_at' => now(),
                'error_message' => $errors !== [] ? implode('; ', $errors) : null,
            ]);

            $response = [
                'import_id' => $import->id,
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
                'failed' => $failed,
                'products' => $products,
            ];
            if ($errors !== []) {
                $response['errors'] = $errors;
            }

            return ApiResponse::success(
                $response,
                $imported + $updated > 0
                    ? ($imported + $updated).' listing(s) imported successfully.'
                    : 'Import completed with no new products.'
            );
        } catch (Throwable $e) {
            Log::error('Etsy import failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * Get Import History
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $profile = $this->profile();

            $perPage = min((int) $request->input('per_page', 20), 50);
            $imports = $this->imports->getByProfile($profile->id, $perPage);

            return ApiResponse::paginated($imports, EtsyImportResource::collection($imports), 'Import history retrieved.');
        } catch (Throwable $e) {
            Log::error('Etsy import history failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Get Import Run Details
     */
    public function detail(Request $request, int $importId): JsonResponse
    {
        try {
            $user = $this->user();
            $profile = $this->profile();

            $import = $this->imports->findWithLogs($importId, $profile->id);
            if (! $import || (int) $import->user_id !== (int) $user->id) {
                return ApiResponse::error('Import record not found.', 404);
            }

            return ApiResponse::success(['import' => new EtsyImportResource($import)], 'Import details retrieved.');
        } catch (Throwable $e) {
            Log::error('Etsy import detail failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Cancel Import Run
     */
    public function cancel(Request $request, int $importId): JsonResponse
    {
        try {
            $user = $this->user();
            $profile = $this->profile();

            $import = $this->imports->findForUser($importId, $profile->id, $user->id);
            if (! $import) {
                return ApiResponse::error('Import record not found.', 404);
            }
            if (! $import->isCancellable()) {
                return ApiResponse::error("Import cannot be cancelled in its current state: {$import->status}.", 400);
            }

            $import->update(['status' => EtsyImport::STATUS_CANCELLED, 'completed_at' => now()]);

            return ApiResponse::success(['import' => new EtsyImportResource($import->fresh())], 'Import cancelled.');
        } catch (Throwable $e) {
            Log::error('Etsy import cancel failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Get Imported Products
     */
    public function importedProducts(Request $request): JsonResponse
    {
        try {
            $profile = $this->profile();

            $perPage = min((int) $request->input('per_page', 20), 100);

            $query = Product::source('etsy')
                ->bySeller($profile->id)
                ->latestFirst();

            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('search')) {
                $query->where('title', 'like', '%'.$request->input('search').'%');
            }

            $products = $query->paginate($perPage);

            return ApiResponse::paginated(
                $products,
                $products->getCollection()->map(fn ($p) => $this->toEtsyFormat($p))->values(),
                'Imported Etsy products retrieved.'
            );
        } catch (Throwable $e) {
            Log::error('Etsy imported products failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    private function toEtsyFormat(Product $product): array
    {
        $src = $product->etsy_source_data ?? [];

        return [
            'listing_id' => $product->etsy_listing_id,
            'local_id' => $product->id,
            'title' => $product->title,
            'image' => $product->image_url,
            'price' => $product->price,
            'currency' => $product->currency ?? 'USD',
            'state' => $product->etsy_state,
            'product_url' => $product->etsy_product_url,
            'quantity' => (int) ($src['quantity'] ?? 0),
            'stock' => (int) ($product->stock_quantity ?? 0),
            'tags' => $src['tags'] ?? [],
            'shop_name' => $product->etsy_shop_name,
        ];
    }
}
