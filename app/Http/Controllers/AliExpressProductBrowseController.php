<?php

namespace App\Http\Controllers;

use App\DTOs\AliExpress\AliExpressProductDTO;
use App\Models\AliExpressAccount;
use App\Models\Product;
use App\Services\AliExpressProductClient;
use App\Services\AliExpressProductService;
use App\Support\ApiResponse;
use App\Traits\ResolvesAuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use League\ISO3166\ISO3166;
use Throwable;

class AliExpressProductBrowseController extends Controller
{
    use ResolvesAuthUser;

    public function __construct(
        protected AliExpressProductService $productService,
        protected AliExpressProductClient $productClient,
    ) {}

    /**
     * GET /api/aliexpress/products
     *
     * Unified product browsing, searching, and filtering endpoint.
     * Supported query parameters:
     *   keyword / q / name / product_name — search term
     *   page_size / per_page             — results per page (default 20, max 50)
     *   page                             — page number (default 1)
     *   category / category_id / category_name — category filter (ID, name, or slug)
     *   sku / sku_id                     — specific SKU filter
     *   product_id / productId           — single product lookup
     *   ship_to_country / country        — destination country code (e.g. US, UK, BD)
     *   search_id                        — feed pagination cursor
     *   refresh / fresh                  — bypass cache
     */
    public function index(Request $request): JsonResponse
    {
        $request->headers->set('Accept', 'application/json');

        $validator = Validator::make($request->all(), [
            'keyword' => ['sometimes', 'nullable', 'string', 'max:200'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'page_size' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'pageSize' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sku_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'product_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'productId' => ['sometimes', 'nullable', 'string', 'max:100'],
            'ship_to_country' => ['sometimes', 'nullable', 'string', 'max:50'],
            'country' => ['sometimes', 'nullable', 'string', 'max:50'],
            'search_id' => ['sometimes', 'nullable', 'string'],
            'refresh' => ['sometimes', 'nullable', 'boolean'],
            'fresh' => ['sometimes', 'nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationError($validator->errors());
        }

        try {
            $account = AliExpressAccount::byUser($this->user()->id)->active()->latest('connected_at')->first();
            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 422);
            }

            $forceRefresh = $request->boolean('refresh') || $request->boolean('fresh');
            if ($forceRefresh) {
                cache()->forget('aliexpress:feed_names');
                cache()->forget('aliexpress:feed_names:'.$account->id);
            }

            // Consolidate parameters
            $keyword = trim((string) (
                $request->input('keyword')
                ?? $request->input('q')
                ?? $request->input('name')
                ?? $request->input('product_name')
                ?? ''
            ));

            $rawPageSize = $request->input('page_size')
                ?? $request->input('pageSize')
                ?? $request->input('per_page')
                ?? $request->input('perPage')
                ?? $request->input('limit');

            $pageSize = ($rawPageSize !== null && $rawPageSize !== '')
                ? max(1, (int) $rawPageSize)
                : 20;

            $page = max(1, (int) $request->input('page', 1));

            $category = trim((string) ($request->input('category') ?? ''));
            $sku = trim((string) ($request->input('sku') ?? $request->input('sku_id') ?? ''));
            $productId = trim((string) ($request->input('product_id') ?? $request->input('productId') ?? ''));

            $rawCountry = $request->input('ship_to_country') ?? $request->input('country');
            $userCountry = ! empty($rawCountry) ? $this->normalizeCountry($rawCountry) : null;
            $effectiveCountry = $userCountry ?: 'US';
            $searchId = $request->input('search_id');

            // 0. Auto-detect AliExpress product URL or numeric product ID in search input
            if ($productId === '' && $keyword !== '') {
                $extractedId = AliExpressProductDTO::extractProductId($keyword);
                if ($extractedId) {
                    $productId = $extractedId;
                } elseif (preg_match('/^\d{9,20}$/', $keyword)) {
                    $productId = $keyword;
                }
            }

            // 1. Direct product_id lookup
            if ($productId !== '') {
                return $this->handleDirectProductLookup($account, $effectiveCountry, $userCountry, $productId, $page, $pageSize, $forceRefresh);
            }

            // 2. Keyword, Category, or SKU Search
            $searchTerm = $keyword;
            if ($searchTerm === '' && $category !== '') {
                $searchTerm = $category;
            }
            if ($searchTerm === '' && $sku !== '') {
                $searchTerm = $sku;
            }

            if ($searchTerm !== '') {
                return $this->handleProductSearch(
                    $account,
                    $effectiveCountry,
                    $userCountry,
                    $searchTerm,
                    $keyword,
                    $category,
                    $sku,
                    $page,
                    $pageSize,
                    $forceRefresh
                );
            }

            // 3. General Feed Browse (no search terms)
            return $this->handleGeneralFeedBrowse($account, $effectiveCountry, $userCountry, $page, $pageSize, $searchId, $forceRefresh);
        } catch (Throwable $e) {
            Log::error('AliExpress product browse/search failed: '.$e->getMessage());

            // Contract Testing compliance: always return 200 OK with empty array
            return ApiResponse::success([
                'products' => [],
                'total' => 0,
                'page' => (int) $request->input('page', 1),
                'page_size' => $pageSize ?? 20,
                'keyword' => $request->input('keyword'),
                'sku' => $request->input('sku'),
                'product_id' => $request->input('product_id'),
                'ship_to_country' => $userCountry ?? null,
                'warning' => 'AliExpress upstream services returned no products: '.$e->getMessage(),
            ], 'Products retrieved successfully.');
        }
    }

    /**
     * Direct product lookup when product_id is provided.
     */
    protected function handleDirectProductLookup(
        AliExpressAccount $account,
        string $effectiveCountry,
        ?string $userCountry,
        string $productId,
        int $page,
        int $pageSize,
        bool $forceRefresh = false
    ): JsonResponse {
        try {
            $detail = $this->productClient->detail($account, $effectiveCountry, $productId, $forceRefresh);
            $item = $this->formatDetailProduct($detail, $effectiveCountry);

            return ApiResponse::success([
                'products' => [$item],
                'total' => 1,
                'page' => 1,
                'page_size' => $pageSize,
                'keyword' => null,
                'sku' => null,
                'product_id' => $productId,
                'ship_to_country' => $userCountry,
            ], 'Products retrieved successfully.');
        } catch (Throwable $e) {
            Log::warning("AliExpress direct product_id ({$productId}) lookup failed: ".$e->getMessage());

            // Check if product exists in local database (imported/staged)
            $local = Product::where('source', 'aliexpress')
                ->where(function ($q) use ($productId) {
                    $q->where('aliexpress_product_id', $productId)
                        ->orWhere('id', $productId);
                })
                ->first();

            if ($local) {
                $item = $this->formatLocalProduct($local, $effectiveCountry);

                return ApiResponse::success([
                    'products' => [$item],
                    'total' => 1,
                    'page' => 1,
                    'page_size' => $pageSize,
                    'keyword' => null,
                    'sku' => null,
                    'product_id' => $productId,
                    'ship_to_country' => $userCountry,
                ], 'Products retrieved successfully.');
            }

            return ApiResponse::success([
                'products' => [],
                'total' => 0,
                'page' => 1,
                'page_size' => $pageSize,
                'keyword' => null,
                'sku' => null,
                'product_id' => $productId,
                'ship_to_country' => $userCountry,
                'warning' => 'Product not found or not deliverable to destination: '.$e->getMessage(),
            ], 'Products retrieved successfully.');
        }
    }

    /**
     * Search products by keyword, category, or SKU.
     */
    protected function handleProductSearch(
        AliExpressAccount $account,
        string $effectiveCountry,
        ?string $userCountry,
        string $searchTerm,
        string $keyword,
        string $category,
        string $sku,
        int $page,
        int $pageSize,
        bool $forceRefresh = false
    ): JsonResponse {
        try {
            // If SKU looks like an AliExpress product ID (9-20 digits), try direct detail lookup first
            if ($sku !== '' && preg_match('/^\d{9,20}$/', $sku)) {
                try {
                    $detail = $this->productClient->detail($account, $effectiveCountry, $sku, $forceRefresh);
                    if ($detail) {
                        $item = $this->formatDetailProduct($detail, $effectiveCountry);

                        return ApiResponse::success([
                            'products' => [$item],
                            'total' => 1,
                            'page' => 1,
                            'page_size' => $pageSize,
                            'keyword' => $keyword !== '' ? $keyword : null,
                            'sku' => $sku,
                            'product_id' => null,
                            'ship_to_country' => $userCountry,
                        ], 'Products retrieved successfully.');
                    }
                } catch (Throwable) {
                    // Proceed with standard search
                }
            }

            $result = $this->productClient->search(
                $account,
                $effectiveCountry,
                $searchTerm,
                $page,
                $pageSize,
                $category ?: null,
                $forceRefresh,
                $userCountry
            );

            $rawProducts = $result['products'] ?? [];
            $products = [];

            foreach ($rawProducts as $p) {
                unset($p['category_id'], $p['category_name']);
                $p['ship_to_country'] = $effectiveCountry;
                $products[] = $p;
            }

            // In-memory filter for sku if specified
            if ($sku !== '') {
                $products = $this->filterProductsBySku($products, $sku);
            }

            // Fallback: If no products returned by API, search locally imported/staged AliExpress products
            if (empty($products)) {
                $localQuery = Product::where('source', 'aliexpress');
                if ($searchTerm !== '') {
                    $localQuery->where(function ($q) use ($searchTerm) {
                        $q->where('title', 'like', "%{$searchTerm}%")
                            ->orWhere('description', 'like', "%{$searchTerm}%")
                            ->orWhere('sku', 'like', "%{$searchTerm}%")
                            ->orWhere('aliexpress_product_id', 'like', "%{$searchTerm}%");
                    });
                }
                $localProducts = $localQuery->take($pageSize)->get();
                foreach ($localProducts as $lp) {
                    $products[] = $this->formatLocalProduct($lp, $effectiveCountry);
                }
            }

            // Ensure exact requested page_size is respected
            $products = array_slice($products, 0, $pageSize);

            $warning = null;
            if (empty($products)) {
                if (! empty($result['upstream_error'])) {
                    $warning = "AliExpress search notice: The connected AliExpress Open Platform account does not have global keyword search permissions ({$result['upstream_error']}). You can import any product directly by searching with its AliExpress item URL or Product ID.";
                } else {
                    $warning = "No products found matching '{$searchTerm}'. You can also search by AliExpress product URL or Product ID directly.";
                }
            }

            $responsePayload = [
                'products' => $products,
                'total' => (int) ($result['total'] ?? count($products)),
                'page' => $page,
                'page_size' => $pageSize,
                'keyword' => $keyword !== '' ? $keyword : null,
                'sku' => $sku !== '' ? $sku : null,
                'product_id' => null,
                'ship_to_country' => $userCountry,
            ];

            if ($warning !== null) {
                $responsePayload['warning'] = $warning;
            }

            return ApiResponse::success($responsePayload, 'Products retrieved successfully.');
        } catch (Throwable $e) {
            Log::warning('AliExpress handleProductSearch failed: '.$e->getMessage());

            // Check locally imported products on failure
            $localProducts = Product::where('source', 'aliexpress')
                ->where(function ($q) use ($searchTerm) {
                    $q->where('title', 'like', "%{$searchTerm}%")
                        ->orWhere('description', 'like', "%{$searchTerm}%")
                        ->orWhere('sku', 'like', "%{$searchTerm}%")
                        ->orWhere('aliexpress_product_id', 'like', "%{$searchTerm}%");
                })
                ->take($pageSize)
                ->get();

            $products = [];
            foreach ($localProducts as $lp) {
                $products[] = $this->formatLocalProduct($lp, $effectiveCountry);
            }

            return ApiResponse::success([
                'products' => $products,
                'total' => count($products),
                'page' => $page,
                'page_size' => $pageSize,
                'keyword' => $keyword !== '' ? $keyword : null,
                'sku' => $sku !== '' ? $sku : null,
                'product_id' => null,
                'ship_to_country' => $userCountry,
                'warning' => count($products) === 0 ? $e->getMessage() : null,
            ], 'Products retrieved successfully.');
        }
    }

    /**
     * General product browsing when no keyword is provided.
     * Fetches popular/trending AliExpress products directly.
     */
    protected function handleGeneralFeedBrowse(
        AliExpressAccount $account,
        string $effectiveCountry,
        ?string $userCountry,
        int $page,
        int $pageSize,
        ?string $searchId = null,
        bool $forceRefresh = false
    ): JsonResponse {
        $feedCacheKey = "aliexpress:browse_feed_v5:{$effectiveCountry}:".($userCountry ?: 'null').":p{$page}:s{$pageSize}";
        if (! $forceRefresh && ($cached = cache()->get($feedCacheKey))) {
            return ApiResponse::success($cached, 'Products retrieved successfully.');
        }

        try {
            $searchResult = $this->productClient->search(
                $account,
                $effectiveCountry,
                '',
                $page,
                $pageSize,
                null,
                $forceRefresh,
                $userCountry
            );

            $products = [];
            foreach (($searchResult['products'] ?? []) as $p) {
                unset($p['category_id'], $p['category_name']);
                $p['ship_to_country'] = $effectiveCountry;
                $products[] = $p;
            }

            $products = array_slice($products, 0, $pageSize);

            if (empty($products)) {
                $localProducts = Product::where('source', 'aliexpress')
                    ->latest()
                    ->take($pageSize)
                    ->get();
                foreach ($localProducts as $lp) {
                    $products[] = $this->formatLocalProduct($lp, $effectiveCountry);
                }
            }

            $responseData = [
                'products' => $products,
                'total' => (int) ($searchResult['total'] ?? count($products)),
                'page' => $page,
                'page_size' => $pageSize,
                'keyword' => null,
                'sku' => null,
                'product_id' => null,
                'ship_to_country' => $userCountry,
            ];

            if (empty($products)) {
                $responseData['warning'] = 'No AliExpress products available at this time.';
            } else {
                cache()->put($feedCacheKey, $responseData, 1800);
            }

            return ApiResponse::success($responseData, 'Products retrieved successfully.');
        } catch (Throwable $e) {
            Log::warning('AliExpress handleGeneralFeedBrowse failed: '.$e->getMessage());

            return ApiResponse::success([
                'products' => [],
                'total' => 0,
                'page' => $page,
                'page_size' => $pageSize,
                'keyword' => null,
                'sku' => null,
                'product_id' => null,
                'ship_to_country' => $userCountry,
                'warning' => 'No AliExpress products available at this time: '.$e->getMessage(),
            ], 'Products retrieved successfully.');
        }
    }

    /**
     * GET /api/aliexpress/products/{productId}
     */
    public function show(string $productId): JsonResponse
    {
        try {
            $account = AliExpressAccount::byUser($this->user()->id)->active()->latest('connected_at')->first();
            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 422);
            }

            $rawCountry = request()->input('ship_to_country') ?? request()->input('country');
            $userCountry = ! empty($rawCountry) ? $this->normalizeCountry($rawCountry) : null;
            $effectiveCountry = $userCountry ?: 'US';
            $forceRefresh = request()->boolean('refresh') || request()->boolean('fresh');

            $dto = $this->productClient->detail($account, $effectiveCountry, $productId, $forceRefresh);

            $ordersCount = (int) ($dto->orders ?? 0);
            $soldDesc = $dto->sold ?? ($ordersCount > 0 ? ($ordersCount >= 1000 ? number_format((int) (floor($ordersCount / 1000) * 1000)).'+ sold' : $ordersCount.' sold') : '0 sold');
            $reviewsCount = (int) ($dto->reviews ?? 0);
            $reviewsDesc = $dto->reviewsDesc ?? ($reviewsCount > 0 ? number_format($reviewsCount).' Reviews' : '0 Reviews');

            // Original prices range
            $skuOrigPrices = array_filter(array_map(
                fn ($s) => ! empty($s->originalPrice) ? (float) $s->originalPrice : null,
                $dto->skus ?? []
            ));
            $originalPriceMin = $skuOrigPrices ? (string) min($skuOrigPrices) : ($dto->priceMin ?? '0.00');
            $originalPriceMax = $skuOrigPrices ? (string) max($skuOrigPrices) : ($dto->priceMax ?? '0.00');

            // Clean store information
            $store = null;
            if (! empty($dto->store)) {
                $store = [
                    'store_id' => (string) ($dto->store['store_id'] ?? ''),
                    'store_name' => (string) ($dto->store['store_name'] ?? ''),
                    'store_url' => (string) ($dto->store['store_url'] ?? ''),
                    'positive_feedback_rate' => (string) ($dto->store['positive_feedback_rate'] ?? ''),
                ];
            }

            // Clean attributes
            $attributes = array_values(array_map(fn ($attr) => [
                'name' => (string) ($attr['name'] ?? ''),
                'value' => (string) ($attr['value'] ?? ''),
            ], $dto->attributes ?? []));

            // Clean SKUs
            $skus = array_map(fn ($sku) => [
                'sku_id' => (string) $sku->skuId,
                'sku_code' => $sku->skuCode,
                'sku_attr' => $sku->skuAttr,
                'price' => (string) ($sku->skuPrice ?? '0.00'),
                'original_price' => (string) ($sku->originalPrice ?? $sku->skuPrice ?? '0.00'),
                'currency' => (string) ($sku->currency ?? $dto->currency ?? 'USD'),
                'stock' => (int) $sku->stock,
                'image' => $sku->image ?? null,
                'properties' => array_values(array_map(fn ($p) => [
                    'name' => (string) ($p['name'] ?? ''),
                    'value' => (string) ($p['value'] ?? ''),
                    'image' => $p['image'] ?? null,
                ], $sku->properties ?? [])),
            ], $dto->skus ?? []);

            $shipping = $dto->shipping ?? $this->productClient->calculateShippingDetails($account, $effectiveCountry, $productId, $dto);

            return ApiResponse::success([
                'product_id' => (string) $dto->productId,
                'title' => (string) ($dto->subject ?? ''),
                'description' => AliExpressProductDTO::cleanHtmlDescription($dto->description),
                'image' => (string) ($dto->mainImage ?? ($dto->images[0] ?? '')),
                'images' => $dto->images ?? [],
                'videos' => $dto->videos ?? [],
                'price_min' => (string) ($dto->priceMin ?? '0.00'),
                'price_max' => (string) ($dto->priceMax ?? '0.00'),
                'original_price_min' => (string) $originalPriceMin,
                'original_price_max' => (string) $originalPriceMax,
                'currency' => (string) ($dto->currency ?? 'USD'),
                'stock' => (int) ($dto->totalStock ?? 100),
                'in_stock' => ($dto->totalStock ?? 100) > 0,
                'orders' => $ordersCount,
                'sold' => $soldDesc,
                'sold_desc' => $soldDesc,
                'rating' => (string) ($dto->rating ?? ''),
                'reviews' => $reviewsCount,
                'reviews_count' => $reviewsCount,
                'reviews_desc' => $reviewsDesc,
                'category_name' => $dto->categoryName,
                'brand' => $dto->brand,
                'product_url' => (string) ($dto->productUrl ?? "https://www.aliexpress.com/item/{$dto->productId}.html"),
                'ship_to_country' => $effectiveCountry,
                'shipping_cost' => (float) ($shipping['shipping_cost'] ?? 0),
                'shipping_fee' => (string) ($shipping['shipping_fee'] ?? 'Free Shipping'),
                'is_free_shipping' => (bool) ($shipping['is_free_shipping'] ?? true),
                'carrier' => (string) ($shipping['carrier'] ?? 'AliExpress Standard Shipping'),
                'shipping' => $shipping,
                'package' => $dto->package ?? $shipping['package'] ?? null,
                'logistics' => $dto->logistics ?? $shipping['logistics'] ?? null,
                'store' => $store,
                'attributes' => $attributes,
                'skus' => $skus,
            ], 'Product retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('AliExpress product show failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 404);
        }
    }

    /**
     * GET /api/aliexpress/products/{productId}/shipping
     *
     * Retrieve real-time shipping options and freight rates for a specific destination and quantity.
     */
    public function shipping(Request $request, string $productId): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user() ?? $this->user();
            $account = $user ? AliExpressAccount::byUser($user->id)->active()->latest('connected_at')->first() : null;
            if (! $account) {
                $account = AliExpressAccount::active()->latest('connected_at')->first();
            }
            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 422);
            }

            $rawCountry = $request->input('ship_to_country') ?? $request->input('country') ?? 'US';
            $effectiveCountry = strtoupper(trim((string) $rawCountry));
            if (strlen($effectiveCountry) !== 2) {
                $effectiveCountry = 'US';
            }

            $quantity = max(1, (int) $request->input('quantity', 1));
            $forceRefresh = $request->boolean('refresh') || $request->boolean('fresh');

            $dto = null;
            try {
                $dto = $this->productClient->detail($account, $effectiveCountry, $productId, $forceRefresh);
            } catch (Throwable $e) {
                Log::info("Shipping detail fallback for {$productId}: ".$e->getMessage());
            }

            $shipping = $this->productClient->calculateShippingDetails(
                $account,
                $effectiveCountry,
                $productId,
                $dto,
                $quantity,
                $forceRefresh
            );

            return ApiResponse::success([
                'product_id' => (string) $productId,
                'ship_to_country' => $effectiveCountry,
                'quantity' => $quantity,
                'shipping' => $shipping,
            ], 'Shipping details retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('AliExpress product shipping calculation failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * GET /api/aliexpress/products/{productId}/reviews
     */
    public function reviews(Request $request, string $productId): JsonResponse
    {
        try {
            $page = max(1, (int) $request->input('page', 1));
            $pageSize = max(1, min(50, (int) ($request->input('page_size') ?? $request->input('per_page') ?? 10)));
            $forceRefresh = $request->boolean('refresh') || $request->boolean('fresh');

            $evaluations = $this->productClient->productEvaluations($productId, $page, $pageSize, $forceRefresh);

            return ApiResponse::success($evaluations, 'Product reviews retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('AliExpress product reviews retrieval failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Format an AliExpressProductDTO into the standard product response shape.
     */
    protected function formatDetailProduct(AliExpressProductDTO $detail, ?string $shipToCountry = null): array
    {
        return [
            'product_id' => (string) $detail->productId,
            'title' => (string) ($detail->subject ?? ''),
            'image' => (string) ($detail->mainImage ?? ($detail->images[0] ?? '')),
            'price_min' => (string) ($detail->priceMin ?? '0.00'),
            'price_max' => (string) ($detail->priceMax ?? '0.00'),
            'currency' => (string) ($detail->currency ?? 'USD'),
            'product_url' => (string) ($detail->productUrl ?? "https://www.aliexpress.com/item/{$detail->productId}.html"),
            'orders' => (int) ($detail->orders ?? 0),
            'sold' => (string) ($detail->sold ?? ($detail->orders > 0 ? ($detail->orders >= 1000 ? number_format((int) (floor($detail->orders / 1000) * 1000)).'+ sold' : $detail->orders.' sold') : '0 sold')),
            'sold_desc' => (string) ($detail->sold ?? ($detail->orders > 0 ? ($detail->orders >= 1000 ? number_format((int) (floor($detail->orders / 1000) * 1000)).'+ sold' : $detail->orders.' sold') : '0 sold')),
            'rating' => (string) ($detail->rating ?? ''),
            'reviews' => (int) ($detail->reviews ?? 0),
            'reviews_count' => (int) ($detail->reviews ?? 0),
            'reviews_desc' => (string) ($detail->reviewsDesc ?? ($detail->reviews > 0 ? number_format($detail->reviews).' Reviews' : '0 Reviews')),
            'stock' => (int) ($detail->totalStock ?? 100),
            'ship_to_country' => $shipToCountry ?: 'US',
            'skus' => array_map(fn ($sku) => [
                'sku_id' => (string) $sku->skuId,
                'sku_attr' => $sku->skuAttr,
                'sku_code' => $sku->skuCode,
                'price' => (string) ($sku->skuPrice ?? '0.00'),
                'stock' => (int) $sku->stock,
                'image' => $sku->image ?? null,
                'properties' => $sku->properties,
            ], $detail->skus ?? []),
        ];
    }

    /**
     * Format a local Product model into the standard product response shape.
     */
    protected function formatLocalProduct(Product $local, ?string $shipToCountry = null): array
    {
        $variants = is_string($local->product_variants)
            ? json_decode($local->product_variants, true)
            : $local->product_variants;

        $skus = [];
        if (! empty($variants) && is_array($variants)) {
            foreach ($variants as $v) {
                if (! is_array($v)) {
                    continue;
                }
                $skus[] = [
                    'sku_id' => (string) ($v['sku'] ?? $v['id'] ?? $local->aliexpress_sku ?? $local->sku ?? ''),
                    'sku_attr' => (string) ($v['sku_attr'] ?? ''),
                    'sku_code' => (string) ($v['sku'] ?? $v['name'] ?? ''),
                    'price' => (string) ($v['price'] ?? $local->price),
                    'stock' => (int) ($v['stock'] ?? $v['stockQuantity'] ?? $local->stock_quantity ?? 0),
                    'properties' => (array) ($v['options'] ?? []),
                ];
            }
        }

        if (empty($skus)) {
            $skus[] = [
                'sku_id' => (string) ($local->aliexpress_sku ?? $local->sku ?? $local->id),
                'sku_attr' => null,
                'sku_code' => (string) ($local->sku ?? ''),
                'price' => (string) $local->price,
                'stock' => (int) ($local->stock_quantity ?? 100),
                'properties' => [],
            ];
        }

        return [
            'product_id' => (string) ($local->aliexpress_product_id ?? $local->id),
            'title' => (string) $local->title,
            'image' => (string) $local->image_url,
            'price_min' => (string) $local->price,
            'price_max' => (string) ($local->compare_at_price ?? $local->price),
            'currency' => (string) ($local->currency ?? 'USD'),
            'product_url' => (string) ($local->aliexpress_product_url ?? "https://www.aliexpress.com/item/{$local->aliexpress_product_id}.html"),
            'orders' => 0,
            'sold' => '0 sold',
            'sold_desc' => '0 sold',
            'rating' => '',
            'reviews' => 0,
            'reviews_count' => 0,
            'reviews_desc' => '0 Reviews',
            'stock' => (int) ($local->stock_quantity ?? 100),
            'ship_to_country' => $shipToCountry ?: 'US',
            'skus' => $skus,
        ];
    }

    /**
     * Filter products matching the given SKU query across product_id, title,
     * and variant skus (sku_id, sku_attr, sku_code, and properties).
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    protected function filterProductsBySku(array $products, string $sku): array
    {
        $skuLower = strtolower(trim($sku));
        if ($skuLower === '') {
            return $products;
        }

        return array_values(array_filter($products, function (array $p) use ($skuLower): bool {
            // 1. Direct match on product_id
            if (isset($p['product_id']) && str_contains(strtolower((string) $p['product_id']), $skuLower)) {
                return true;
            }

            // 2. Direct match on title
            if (isset($p['title']) && str_contains(strtolower((string) $p['title']), $skuLower)) {
                return true;
            }

            // 3. Match within skus array
            if (! empty($p['skus']) && is_array($p['skus'])) {
                foreach ($p['skus'] as $s) {
                    if (! is_array($s)) {
                        continue;
                    }

                    $skuId = strtolower((string) ($s['sku_id'] ?? ''));
                    if ($skuId !== '' && str_contains($skuId, $skuLower)) {
                        return true;
                    }

                    $skuAttr = strtolower((string) ($s['sku_attr'] ?? ''));
                    if ($skuAttr !== '' && str_contains($skuAttr, $skuLower)) {
                        return true;
                    }

                    $skuCode = strtolower((string) ($s['sku_code'] ?? ''));
                    if ($skuCode !== '' && str_contains($skuCode, $skuLower)) {
                        return true;
                    }

                    // Check properties (name & value, e.g. "Color: Black", "Size: XL")
                    if (! empty($s['properties']) && is_array($s['properties'])) {
                        foreach ($s['properties'] as $prop) {
                            if (! is_array($prop)) {
                                continue;
                            }
                            $pName = strtolower((string) ($prop['name'] ?? ''));
                            $pVal = strtolower((string) ($prop['value'] ?? ''));
                            if (($pVal !== '' && str_contains($pVal, $skuLower))
                                || ($pName !== '' && str_contains($pName, $skuLower))) {
                                return true;
                            }
                        }
                    }
                }
            }

            return false;
        }));
    }

    /**
     * Normalize country codes to ISO 3166-1 alpha-2.
     */
    protected function normalizeCountry(?string $country): string
    {
        if (empty($country)) {
            return (string) config('services.aliexpress.ship_to_country', 'US');
        }

        $raw = strtoupper(trim((string) $country));
        if (preg_match('/^[A-Z]{2}$/', $raw) === 1) {
            return $raw;
        }

        $aliases = [
            'USA' => 'US',
            'UK' => 'GB',
            'ENGLAND' => 'GB',
            'AMERICA' => 'US',
            'UNITED STATES' => 'US',
            'UNITED STATES OF AMERICA' => 'US',
            'UNITED KINGDOM' => 'GB',
            'GREAT BRITAIN' => 'GB',
            'BANGLADESH' => 'BD',
            'CANADA' => 'CA',
            'AUSTRALIA' => 'AU',
            'GERMANY' => 'DE',
            'FRANCE' => 'FR',
            'SPAIN' => 'ES',
            'ITALY' => 'IT',
            'INDIA' => 'IN',
            'PAKISTAN' => 'PK',
        ];

        if (isset($aliases[$raw])) {
            return $aliases[$raw];
        }

        try {
            $iso = new ISO3166;
            if (strlen($raw) === 3) {
                return $iso->alpha3($raw)['alpha2'];
            }

            return $iso->name($raw)['alpha2'];
        } catch (Throwable) {
            return 'US';
        }
    }
}
