<?php

namespace App\Http\Controllers;

use App\Exceptions\Etsy\EtsyApiException;
use App\Models\EtsyAccount;
use App\Services\EtsyListingService;
use App\Support\ApiResponse;
use App\Traits\ResolvesAuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Etsy listing browse endpoints (public Open API v3 data, platform key).
 *
 * Mirrors AliExpressProductBrowseController: thin orchestration only —
 * validation inline via $request->validate (browse params), API work in
 * EtsyListingService, responses via ApiResponse.
 */
class EtsyProductBrowseController extends Controller
{
    use ResolvesAuthUser;

    public function __construct(
        protected EtsyListingService $listings,
    ) {}

    /**
     * GET /api/etsy/products
     *
     * Supported params:
     *   keyword  — keyword search (optional)
     *   limit    — results per page, default 20, max 100
     *   offset   — pagination offset
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        // Same-to-same with AliExpress: browsing operates through the
        // seller's connected Etsy account (OAuth Bearer), not platform-only.
        $account = EtsyAccount::byUser($this->user()->id)->active()->latest('connected_at')->first();
        if (! $account) {
            return ApiResponse::error('No connected Etsy account found. Connect your Etsy account first.', 422);
        }

        try {
            $result = $this->listings->search(
                keyword: trim((string) $request->input('keyword', '')),
                limit: (int) $request->input('limit', 20),
                offset: (int) $request->input('offset', 0),
                account: $account,
            );

            return ApiResponse::success([
                'products' => array_map(
                    fn ($dto) => $this->format($dto),
                    $result['listings']
                ),
                'total' => $result['total'],
                'offset' => $result['offset'],
                'limit' => (int) $request->input('limit', 20),
            ], 'Etsy products retrieved.');
        } catch (EtsyApiException $e) {
            Log::warning('Etsy product browse: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 503);
        } catch (Throwable $e) {
            Log::error('Etsy product browse failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * GET /api/etsy/products/{listingId}
     */
    public function show(int $listingId): JsonResponse
    {
        $account = EtsyAccount::byUser($this->user()->id)->active()->latest('connected_at')->first();
        if (! $account) {
            return ApiResponse::error('No connected Etsy account found. Connect your Etsy account first.', 422);
        }

        try {
            $dto = $this->listings->getListing($listingId, $account);

            return ApiResponse::success($this->format($dto), 'Etsy product retrieved.');
        } catch (EtsyApiException $e) {
            return ApiResponse::error($e->getMessage(), 503);
        } catch (Throwable $e) {
            Log::error('Etsy product show failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * List-item shape shared by browse and detail endpoints (matches the
     * AliExpress list-item contract used by the Flutter picker).
     */
    protected function format(EtsyListingDTO $dto): array
    {
        return [
            'listing_id' => $dto->listingId,
            'title' => $dto->title,
            'description' => $dto->description,
            'price' => $dto->price,
            'currency' => $dto->currency,
            'state' => $dto->state,
            'quantity' => $dto->quantity,
            'image' => $dto->images[0]['url'] ?? null,
            'images' => array_map(fn (array $image) => $image['url'], $dto->images),
            'shop_name' => $dto->shopName,
            'url' => $dto->url,
            'tags' => $dto->tags,
            'materials' => $dto->materials,
            'taxonomy_path' => $dto->taxonomyPath,
            'who_made' => $dto->whoMade,
            'when_made' => $dto->whenMade,
            'is_personalizable' => $dto->isPersonalizable,
            'has_variations' => $dto->hasVariations,
        ];
    }
}
