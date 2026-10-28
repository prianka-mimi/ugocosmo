<?php

namespace App\Http\Controllers;

use App\DTOs\AliExpress\AliExpressImportOptions;
use App\DTOs\AliExpress\AliExpressProductDTO;
use App\Http\Requests\AliExpressImportRequest;
// use App\Http\Resources\AliExpressImportResource;
use App\Manager\ImageManager;
use App\Models\AliExpressAccount;
use App\Models\AliExpressImport;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Repositories\AliExpressAccountRepository;
use App\Repositories\AliExpressImportRepository;
use App\Services\AliExpressImportService;
use App\Services\AliExpressProductClient;
use App\Services\AliExpressProductService;
use App\Support\ApiResponse;
use App\Traits\ResolvesAuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AliExpressImportController extends Controller
{
    use ResolvesAuthUser;

    public function __construct(
        protected AliExpressAccountRepository $accounts,
        protected AliExpressImportRepository $imports,
        protected AliExpressImportService $importService,
        protected AliExpressProductService $productService,
        protected AliExpressProductClient $productClient,
    ) {}

    /**
     * Initiate AliExpress Product Import (Stages products for review)
     *
     * Fetches products from AliExpress, resolves categories dynamically,
     * strips profit margins, and stages the products in this import session
     * without storing them into the live products table yet.
     */
    public function import(AliExpressImportRequest $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return ApiResponse::error('Authentication required.', 401);
            }

            $account = AliExpressAccount::byUser($user->id)->active()->latest('connected_at')->first();
            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 422);
            }

            $productIds = [];
            $invalidUrls = [];

            $urls = $request->input('product_urls', []);
            if (is_string($urls)) {
                $urls = array_filter(array_map('trim', explode(',', $urls)));
            }
            foreach ($urls as $url) {
                $id = AliExpressProductDTO::extractProductId($url);
                if ($id) {
                    $productIds[] = $id;
                } else {
                    $invalidUrls[] = $url;
                }
            }

            $ids = $request->input('product_ids', []);
            if (is_string($ids)) {
                $ids = array_filter(array_map('trim', explode(',', $ids)));
            }
            foreach ($ids as $rawId) {
                $id = AliExpressProductDTO::extractProductId((string) $rawId);
                if ($id) {
                    $productIds[] = $id;
                }
            }

            if ($productIds === []) {
                return ApiResponse::error('No valid AliExpress product IDs or URLs provided.', 422);
            }

            $productIds = array_values(array_unique($productIds));

            $alreadyImported = Product::where('source', 'aliexpress')
                ->where('aliexpress_account_id', $account->id)
                ->whereIn('aliexpress_product_id', $productIds)
                ->pluck('aliexpress_product_id')
                ->toArray();

            $newIds = array_values(array_diff($productIds, $alreadyImported));

            if ($newIds === []) {
                return ApiResponse::success([
                    'imported' => 0,
                    'skipped' => count($alreadyImported),
                    'failed' => 0,
                    'skipped_product_ids' => $alreadyImported,
                ], 'All product(s) are already imported.');
            }

            $options = AliExpressImportOptions::fromRequest($request->validated());

            $import = $this->imports->create([
                'aliexpress_account_id' => $account->id,
                'profile_id' => $account->profile_id,
                'user_id' => $user->id,
                'status' => 'staged',
                'total_products' => count($newIds),
                'total_processed' => 0,
                'imported_count' => 0,
                'updated_count' => 0,
                'skipped_count' => count($alreadyImported),
                'failed_count' => 0,
                'selected_product_ids' => $newIds,
                'filters' => $options->toArray(),
                'started_at' => now(),
            ]);

            $stagedProducts = [];
            $errors = [];

            $rawCountry = $request->input('ship_to_country') ?? $request->input('country') ?? 'US';
            $effectiveCountry = strtoupper(trim((string) $rawCountry));
            if (strlen($effectiveCountry) !== 2) {
                $effectiveCountry = 'US';
            }

            foreach ($newIds as $productId) {
                try {
                    $dto = null;
                    try {
                        $dto = $this->productClient->detail($account, $effectiveCountry, $productId);
                    } catch (Throwable $clientEx) {
                        Log::warning("AliExpress productClient->detail failed for {$productId}: ".$clientEx->getMessage());
                    }

                    if (! $dto) {
                        $dto = $this->productService->getProduct($account, $productId, $effectiveCountry);
                    }

                    $categoryId = $options->categoryId;
                    if (! $categoryId && ! empty($dto->categoryName)) {
                        $cat = ProductCategory::firstOrCreate(
                            ['name' => trim($dto->categoryName)],
                            ['slug' => Str::slug($dto->categoryName), 'product_type' => 'physical', 'is_active' => true]
                        );
                        $categoryId = $cat->id;
                    }

                    $cost = $dto->costPrice();
                    $margin = $options->profitMargin ?? 0;
                    $price = $margin > 0 ? round($cost * (1 + $margin / 100), 2) : $cost;

                    $skuOrigPrices = array_filter(array_map(
                        fn ($s) => ! empty($s->originalPrice) ? (float) $s->originalPrice : null,
                        $dto->skus ?? []
                    ));
                    $origMin = $skuOrigPrices ? min($skuOrigPrices) : (float) ($dto->priceMin ?? $cost);
                    $origMax = $skuOrigPrices ? max($skuOrigPrices) : (float) ($dto->priceMax ?? $cost);

                    $ordersCount = (int) ($dto->orders ?? 0);
                    $soldDesc = $dto->sold ?? ($ordersCount > 0 ? ($ordersCount >= 1000 ? number_format((int) (floor($ordersCount / 1000) * 1000)).'+ sold' : $ordersCount.' sold') : '0 sold');
                    $reviewsCount = (int) ($dto->reviews ?? 0);
                    $reviewsDesc = $dto->reviewsDesc ?? ($reviewsCount > 0 ? number_format($reviewsCount).' Reviews' : '0 Reviews');
                    $totalStock = (int) ($dto->totalStock > 0 ? $dto->totalStock : 100);

                    $variants = array_map(fn ($sku) => $sku->toLocalVariant(), $dto->skus ?? []);
                    $skusList = array_map(fn ($sku) => [
                        'sku_id' => (string) $sku->skuId,
                        'sku_code' => $sku->skuCode,
                        'sku_attr' => $sku->skuAttr,
                        'price' => (string) ($sku->skuPrice ?? '0.00'),
                        'original_price' => (string) ($sku->originalPrice ?? $sku->skuPrice ?? '0.00'),
                        'currency' => (string) ($sku->currency ?? $dto->currency ?? 'USD'),
                        'stock' => (int) ($sku->stock > 0 ? $sku->stock : 10),
                        'image' => $sku->image ?? null,
                        'properties' => $sku->properties ?? [],
                    ], $dto->skus ?? []);

                    $mainImage = $dto->mainImage ?? ($dto->images[0] ?? null);
                    $shippingData = $dto->shipping ?? $this->productClient->calculateShippingDetails($account, $effectiveCountry, $dto->productId, $dto);

                    $stagedProducts[] = [
                        'aliexpress_product_id' => (string) $dto->productId,
                        'product_id' => (string) $dto->productId,
                        'title' => $dto->subject,
                        'description' => $dto->description ? trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($dto->description, ENT_QUOTES | ENT_HTML5, 'UTF-8')))) ?: null : null,
                        'price' => (float) $price,
                        'price_min' => (string) ($dto->priceMin ?? number_format($price, 2, '.', '')),
                        'price_max' => (string) ($dto->priceMax ?? number_format($price, 2, '.', '')),
                        'original_price' => (string) $origMin,
                        'original_price_min' => (string) $origMin,
                        'original_price_max' => (string) $origMax,
                        'supplier_cost' => (float) $cost,
                        'currency' => $dto->currency ?? 'USD',
                        'category_id' => $categoryId,
                        'category_name' => $dto->categoryName,
                        'stock' => $totalStock,
                        'stock_quantity' => $totalStock,
                        'in_stock' => $totalStock > 0,
                        'orders' => $ordersCount,
                        'sold' => $soldDesc,
                        'sold_desc' => $soldDesc,
                        'rating' => (string) ($dto->rating ?? ''),
                        'reviews' => $reviewsCount,
                        'reviews_count' => $reviewsCount,
                        'reviews_desc' => $reviewsDesc,
                        'image' => $mainImage,
                        'image_url' => $mainImage,
                        'images' => $dto->images ?: ($mainImage ? [$mainImage] : []),
                        'videos' => $dto->videos ?: [],
                        'shipping_cost' => (float) ($shippingData['shipping_cost'] ?? 0),
                        'shipping_fee' => (string) ($shippingData['shipping_fee'] ?? 'Free Shipping'),
                        'is_free_shipping' => (bool) ($shippingData['is_free_shipping'] ?? true),
                        'carrier' => (string) ($shippingData['carrier'] ?? 'AliExpress Standard Shipping'),
                        'shipping' => $shippingData,
                        'package' => $dto->package ?? $shippingData['package'] ?? null,
                        'logistics' => $dto->logistics ?? $shippingData['logistics'] ?? null,
                        'is_active' => false,
                        'product_url' => $dto->productUrl ?? "https://www.aliexpress.com/item/{$dto->productId}.html",
                        'ship_to_country' => $effectiveCountry,
                        'product_variants' => $variants,
                        'skus' => $skusList,
                    ];
                } catch (Throwable $e) {
                    Log::warning("AliExpress stage getProduct failed for {$productId}: ".$e->getMessage());

                    $fallbackShipping = [
                        'shipping_cost' => 0.0,
                        'shipping_fee' => 'Free Shipping',
                        'is_free_shipping' => true,
                        'carrier' => 'AliExpress Standard Shipping',
                        'shipping_method' => 'AliExpress Standard Shipping',
                        'delivery_time' => '10-15 business days',
                        'estimated_delivery' => now()->addDays(10)->format('M d').' - '.now()->addDays(18)->format('M d'),
                        'tracking_available' => true,
                        'ship_from_country' => 'CN',
                        'ship_to_country' => $effectiveCountry,
                        'shipping_options' => [
                            [
                                'id' => 'standard_shipping',
                                'name' => 'AliExpress Standard Shipping',
                                'cost' => 0.0,
                                'carrier' => 'AliExpress Standard Shipping',
                                'currency' => 'USD',
                                'estimated_delivery' => '10-15 business days',
                                'tracking_supported' => true,
                                'is_free' => true,
                            ],
                        ],
                        'package' => null,
                        'logistics' => null,
                    ];

                    // Fallback staged item so seller can review and customize
                    $stagedProducts[] = [
                        'aliexpress_product_id' => (string) $productId,
                        'product_id' => (string) $productId,
                        'title' => "AliExpress Product {$productId}",
                        'description' => null,
                        'price' => 10.00,
                        'price_min' => '10.00',
                        'price_max' => '10.00',
                        'original_price' => '10.00',
                        'original_price_min' => '10.00',
                        'original_price_max' => '10.00',
                        'supplier_cost' => 10.00,
                        'currency' => 'USD',
                        'category_id' => $options->categoryId,
                        'category_name' => null,
                        'stock' => 100,
                        'stock_quantity' => 100,
                        'in_stock' => true,
                        'orders' => 0,
                        'sold' => '0 sold',
                        'sold_desc' => '0 sold',
                        'rating' => '',
                        'reviews' => 0,
                        'reviews_count' => 0,
                        'reviews_desc' => '0 Reviews',
                        'image' => null,
                        'image_url' => null,
                        'images' => [],
                        'videos' => [],
                        'shipping_cost' => 0.0,
                        'shipping_fee' => 'Free Shipping',
                        'is_free_shipping' => true,
                        'carrier' => 'AliExpress Standard Shipping',
                        'shipping' => $fallbackShipping,
                        'package' => null,
                        'logistics' => null,
                        'is_active' => false,
                        'product_url' => "https://www.aliexpress.com/item/{$productId}.html",
                        'ship_to_country' => $effectiveCountry,
                        'product_variants' => null,
                        'skus' => [],
                    ];
                }
            }

            $import->update([
                'status' => 'staged',
                'total_products' => count($stagedProducts),
                'summary' => [
                    'staged_products' => $stagedProducts,
                ],
            ]);

            $cleanProducts = array_map(fn ($p) => $this->formatStagedProduct($p), $stagedProducts);

            $response = [
                'import_id' => $import->id,
                'status' => 'staged',
                'total_products' => count($cleanProducts),
                'products' => $cleanProducts,
            ];
            if ($alreadyImported !== []) {
                $response['skipped_count'] = count($alreadyImported);
            }

            return ApiResponse::success(
                $response,
                count($cleanProducts).' product(s) staged for review. Review, edit or delete items before final import.'
            );
        } catch (Throwable $e) {
            Log::error('AliExpress import failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * Resolve the target import run for the account.
     */
    protected function resolveImport(AliExpressAccount $account, Request $request, ?int $importId = null): ?AliExpressImport
    {
        $id = $importId ?: $request->input('import_id');
        if ($id) {
            return $this->imports->findForUser((int) $id, $account->id, Auth::guard('jwt')->id());
        }

        // Return latest staged import if available
        $staged = AliExpressImport::byAccount($account->id)
            ->where('status', 'staged')
            ->latest('id')
            ->first();

        if ($staged) {
            return $staged;
        }

        // Fallback to latest import overall
        return AliExpressImport::byAccount($account->id)->latest('id')->first();
    }

    /**
     * Get Import History
     *
     * Returns a unified summary of all staged and imported AliExpress products
     * for the account. Staged products come from pending import sessions;
     * imported products come from the products table.
     *
     * Query params:
     *   ?status=staged|imported   filter by status
     *   ?per_page=20              pagination
     *   ?search=keyword           filter by title
     */
    public function history(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            $account = AliExpressAccount::byUser($user->id)->active()->latest('connected_at')->first();
            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 422);
            }

            $rawCountry = $request->input('ship_to_country') ?? $request->input('country') ?? 'US';
            $effectiveCountry = strtoupper(trim((string) $rawCountry));
            if (strlen($effectiveCountry) !== 2) {
                $effectiveCountry = 'US';
            }

            $filterStatus = $request->input('status'); // null | 'staged' | 'imported'
            $search = $request->input('search');
            $perPage = min((int) $request->input('per_page', 20), 100);
            $page = max((int) $request->input('page', 1), 1);

            $allProducts = [];

            // ── 1. Staged products from pending import sessions ────────────────
            if (! $filterStatus || $filterStatus === 'staged') {
                $stagedImports = AliExpressImport::byAccount($account->id)
                    ->where('status', 'staged')
                    ->latest('id')
                    ->get();

                $seenStaged = [];
                foreach ($stagedImports as $imp) {
                    foreach ($imp->summary['staged_products'] ?? [] as $p) {
                        $pid = (string) ($p['aliexpress_product_id'] ?? $p['product_id'] ?? '');
                        if ($pid === '' || isset($seenStaged[$pid])) {
                            continue;
                        }
                        $seenStaged[$pid] = true;

                        $formatted = $this->formatStagedProduct($p);
                        $formatted['local_id'] = null;
                        $formatted['status'] = 'staged';

                        if ($search && ! str_contains(strtolower($formatted['title']), strtolower($search))) {
                            continue;
                        }

                        $allProducts[] = $formatted;
                    }
                }
            }

            // ── 2. Imported products from the products table ───────────────────
            if (! $filterStatus || $filterStatus === 'imported') {
                $query = Product::source('aliexpress')
                    ->where('aliexpress_account_id', $account->id)
                    ->latestFirst();

                if ($search) {
                    $query->where('title', 'like', '%'.$search.'%');
                }

                $importedProducts = $query->get();

                foreach ($importedProducts as $product) {
                    $src = is_array($product->aliexpress_source_data) ? $product->aliexpress_source_data : [];

                    $shippingCost = (float) ($src['shipping_cost'] ?? $product->shipping_cost ?? 0);
                    $isFreeShipping = isset($src['is_free_shipping']) ? (bool) $src['is_free_shipping'] : ($shippingCost <= 0);
                    $shippingFee = (string) ($src['shipping_fee'] ?? ($isFreeShipping ? 'Free Shipping' : ('$'.number_format($shippingCost, 2))));
                    $carrier = (string) ($src['carrier'] ?? 'AliExpress Standard Shipping');
                    $reviewsCount = (int) ($src['reviews_count'] ?? $src['reviews'] ?? 0);
                    $orders = (int) ($src['orders'] ?? 0);
                    $sold = (string) ($src['sold'] ?? $src['sold_desc'] ?? ($orders > 0 ? ($orders >= 1000 ? number_format((int) (floor($orders / 1000) * 1000)).'+ sold' : $orders.' sold') : '0 sold'));

                    $allProducts[] = [
                        'product_id' => (string) $product->aliexpress_product_id,
                        'aliexpress_product_id' => (string) $product->aliexpress_product_id,
                        'local_id' => $product->id,
                        'title' => $product->title,
                        'description' => $product->clean_description ?? $product->description,
                        'price' => (float) $product->price,
                        'price_min' => (string) ($src['price_min'] ?? number_format((float) $product->price, 2, '.', '')),
                        'price_max' => (string) ($src['price_max'] ?? number_format((float) $product->price, 2, '.', '')),
                        'original_price' => (string) ($src['original_price'] ?? $src['original_price_min'] ?? number_format((float) $product->price, 2, '.', '')),
                        'original_price_max' => (string) ($src['original_price_max'] ?? $src['original_price'] ?? number_format((float) $product->price, 2, '.', '')),
                        'supplier_cost' => (float) ($src['cost_price'] ?? $src['supplier_cost'] ?? $product->price),
                        'currency' => $product->currency ?? 'USD',
                        'image_url' => ImageManager::fileUrl($product->image_url),
                        'images' => array_map([ImageManager::class, 'fileUrl'], is_array($product->images) ? $product->images : []),
                        'stock' => (int) $product->stock_quantity,
                        'in_stock' => (int) $product->stock_quantity > 0,
                        'orders' => $orders,
                        'sold' => $sold,
                        'rating' => (string) ($src['rating'] ?? ''),
                        'reviews' => $reviewsCount,
                        'reviews_desc' => (string) ($src['reviews_desc'] ?? ($reviewsCount > 0 ? number_format($reviewsCount).' Reviews' : '0 Reviews')),
                        'category_id' => $product->category_id,
                        'category_name' => $product->category?->name,
                        'shipping_cost' => $shippingCost,
                        'shipping_fee' => $shippingFee,
                        'is_free_shipping' => $isFreeShipping,
                        'carrier' => $carrier,
                        'shipping' => $this->normalizeShipping(
                            $src['shipping'] ?? null,
                            (string) ($src['ship_to_country'] ?? 'US'),
                            $shippingCost,
                            $carrier,
                            (string) ($product->currency ?? 'USD')
                        ),
                        'product_url' => $product->aliexpress_product_url ?? "https://www.aliexpress.com/item/{$product->aliexpress_product_id}.html",
                        'ship_to_country' => (string) ($src['ship_to_country'] ?? 'US'),
                        'is_active' => (bool) $product->is_active,
                        'status' => 'imported',
                        'product_variants' => array_map(fn ($v) => [
                            'sku' => $v['sku'] ?? $v['sku_code'] ?? '',
                            'image' => $v['image'] ?? null,
                            'price' => $v['price'] ?? '0.00',
                            'stock' => (int) ($v['stock'] ?? $v['stockQuantity'] ?? 0),
                            'options' => $v['options'] ?? [],
                            'original_price' => $v['original_price'] ?? null,
                        ], is_array($product->product_variants) ? $product->product_variants : []),
                    ];
                }
            }

            // ── 3. Paginate in-memory ──────────────────────────────────────────
            $total = count($allProducts);
            $stagedCount = count(array_filter($allProducts, fn ($p) => $p['status'] === 'staged'));
            $importedCount = count(array_filter($allProducts, fn ($p) => $p['status'] === 'imported'));
            $offset = ($page - 1) * $perPage;
            $paged = array_slice($allProducts, $offset, $perPage);

            return ApiResponse::success([
                'status' => $filterStatus ?? 'all',
                'total_products' => $total,
                'staged_count' => $stagedCount,
                'imported_count' => $importedCount,
                'products' => $paged,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => (int) ceil($total / $perPage) ?: 1,
                ],
            ], 'Import history retrieved.');
        } catch (Throwable $e) {
            Log::error('AliExpress import history failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Get Single Product Details from Staged Import or Finalized Products
     *
     * GET /api/aliexpress/import/history/{productId}
     */
    public function detail(Request $request, string $productId): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            $account = AliExpressAccount::byUser($user->id)->active()->latest('connected_at')->first();
            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 422);
            }

            // 1. Search in all staged sessions
            $stagedImports = AliExpressImport::byAccount($account->id)
                ->where('status', 'staged')
                ->latest('id')
                ->get();

            $found = null;
            foreach ($stagedImports as $imp) {
                foreach ($imp->summary['staged_products'] ?? [] as $p) {
                    $pid = (string) ($p['aliexpress_product_id'] ?? $p['product_id'] ?? '');
                    if ($pid === (string) $productId) {
                        $found = $p;
                        break 2;
                    }
                }
            }

            // 2. Fallback: Search across all import histories for this account
            if (! $found) {
                $allImports = AliExpressImport::byAccount($account->id)
                    ->latest('id')
                    ->get();
                foreach ($allImports as $imp) {
                    foreach ($imp->summary['staged_products'] ?? [] as $p) {
                        $pid = (string) ($p['aliexpress_product_id'] ?? $p['product_id'] ?? '');
                        if ($pid === (string) $productId) {
                            $found = $p;
                            break 2;
                        }
                    }
                }
            }

            // 3. Fallback: Search in products table if already finalized
            if (! $found) {
                $dbProduct = Product::where('aliexpress_product_id', (string) $productId)
                    ->where('aliexpress_account_id', $account->id)
                    ->first();
                if ($dbProduct) {
                    $found = [
                        'aliexpress_product_id' => (string) $dbProduct->aliexpress_product_id,
                        'product_id' => (string) $dbProduct->aliexpress_product_id,
                        'title' => $dbProduct->title,
                        'description' => $dbProduct->clean_description ?? $dbProduct->description,
                        'price' => (float) $dbProduct->price,
                        'supplier_cost' => (float) ($dbProduct->aliexpress_source_data['cost_price'] ?? 0),
                        'currency' => $dbProduct->currency ?? 'USD',
                        'image' => $dbProduct->image_url,
                        'image_url' => $dbProduct->image_url,
                        'images' => $dbProduct->images ?? [],
                        'stock_quantity' => (int) $dbProduct->stock_quantity,
                        'stock' => (int) $dbProduct->stock_quantity,
                        'category_id' => $dbProduct->category_id,
                        'category_name' => $dbProduct->category?->name,
                        'shipping_cost' => (float) ($dbProduct->shipping_cost ?? 0),
                        'is_active' => (bool) $dbProduct->is_active,
                        'product_url' => $dbProduct->aliexpress_product_url,
                        'product_variants' => $dbProduct->product_variants ?? [],
                        'skus' => $dbProduct->product_variants ?? [],
                    ];
                }
            }

            $rawCountry = $request->input('ship_to_country') ?? $request->input('country') ?? 'US';
            $effectiveCountry = strtoupper(trim((string) $rawCountry));
            if (strlen($effectiveCountry) !== 2) {
                $effectiveCountry = 'US';
            }

            // 4. Fallback: Fetch directly from AliExpress if valid ID
            if (! $found) {
                try {
                    $liveDto = $this->productClient->detail($account, $effectiveCountry, $productId);
                    if ($liveDto) {
                        $found = [
                            'aliexpress_product_id' => (string) $liveDto->productId,
                            'product_id' => (string) $liveDto->productId,
                            'title' => $liveDto->subject,
                            'description' => $liveDto->description,
                            'price' => (float) ($liveDto->priceMin ?? 0),
                            'price_min' => (string) ($liveDto->priceMin ?? '0.00'),
                            'price_max' => (string) ($liveDto->priceMax ?? '0.00'),
                            'original_price' => (string) ($liveDto->priceMin ?? '0.00'),
                            'original_price_min' => (string) ($liveDto->priceMin ?? '0.00'),
                            'original_price_max' => (string) ($liveDto->priceMax ?? '0.00'),
                            'supplier_cost' => $liveDto->costPrice(),
                            'currency' => $liveDto->currency ?? 'USD',
                            'image' => $liveDto->mainImage ?? ($liveDto->images[0] ?? null),
                            'image_url' => $liveDto->mainImage ?? ($liveDto->images[0] ?? null),
                            'images' => $liveDto->images ?? [],
                            'videos' => $liveDto->videos ?? [],
                            'stock' => $liveDto->totalStock > 0 ? $liveDto->totalStock : 100,
                            'stock_quantity' => $liveDto->totalStock > 0 ? $liveDto->totalStock : 100,
                            'in_stock' => true,
                            'orders' => $liveDto->orders,
                            'sold' => $liveDto->sold,
                            'sold_desc' => $liveDto->sold,
                            'rating' => (string) ($liveDto->rating ?? ''),
                            'reviews' => $liveDto->reviews,
                            'reviews_count' => $liveDto->reviews,
                            'reviews_desc' => $liveDto->reviewsDesc,
                            'category_id' => null,
                            'category_name' => $liveDto->categoryName,
                            'shipping_cost' => 0.0,
                            'product_url' => $liveDto->productUrl,
                            'ship_to_country' => $effectiveCountry,
                            'is_active' => false,
                            'skus' => array_map(fn ($sku) => [
                                'sku_id' => (string) $sku->skuId,
                                'sku_code' => $sku->skuCode,
                                'sku_attr' => $sku->skuAttr,
                                'price' => (string) ($sku->skuPrice ?? '0.00'),
                                'original_price' => (string) ($sku->originalPrice ?? $sku->skuPrice ?? '0.00'),
                                'currency' => (string) ($sku->currency ?? $liveDto->currency ?? 'USD'),
                                'stock' => (int) ($sku->stock > 0 ? $sku->stock : 10),
                                'image' => $sku->image ?? null,
                                'properties' => $sku->properties ?? [],
                            ], $liveDto->skus ?? []),
                            'product_variants' => array_map(fn ($sku) => $sku->toLocalVariant(), $liveDto->skus ?? []),
                            'package' => $liveDto->package,
                            'logistics' => $liveDto->logistics,
                            'shipping' => $liveDto->shipping,
                        ];
                    }
                } catch (Throwable $e) {
                    Log::info("Direct AliExpress detail fallback failed for {$productId}: ".$e->getMessage());
                }
            }

            if (! $found) {
                return ApiResponse::error("Product {$productId} not found in staged imports.", 404);
            }

            $found = $this->enrichStagedProductIfIncomplete($found, $account, $effectiveCountry);

            return ApiResponse::success([
                'product' => $this->formatStagedProduct($found),
            ], 'Staged product detail retrieved.');
        } catch (Throwable $e) {
            Log::error('AliExpress import single product detail failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Delete a single product from staged import
     */
    public function removeSingleStagedProduct(Request $request, string $productId): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            $account = AliExpressAccount::byUser($user->id)->active()->latest('connected_at')->first();
            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 422);
            }

            $stagedImports = AliExpressImport::byAccount($account->id)
                ->where('status', 'staged')
                ->get();

            $found = false;
            foreach ($stagedImports as $import) {
                $staged = $import->summary['staged_products'] ?? [];
                $remaining = [];
                foreach ($staged as $item) {
                    $pid = (string) ($item['aliexpress_product_id'] ?? $item['product_id'] ?? '');
                    if ($pid === (string) $productId) {
                        $found = true;
                    } else {
                        $remaining[] = $item;
                    }
                }

                $summary = $import->summary ?? [];
                $summary['staged_products'] = array_values($remaining);

                $selectedIds = array_values(array_diff($import->selected_product_ids ?? [], [(string) $productId]));

                $import->update([
                    'summary' => $summary,
                    'selected_product_ids' => $selectedIds,
                    'total_products' => count($remaining),
                ]);
            }

            if (! $found) {
                return ApiResponse::error("Product {$productId} not found in staged imports.", 404);
            }

            // Gather all remaining staged products across all sessions
            $allRemaining = [];
            foreach ($stagedImports->fresh() as $imp) {
                foreach ($imp->summary['staged_products'] ?? [] as $p) {
                    $pid = (string) ($p['aliexpress_product_id'] ?? $p['product_id'] ?? '');
                    if ($pid !== '') {
                        $allRemaining[$pid] = $p;
                    }
                }
            }

            $cleanRemaining = array_map(fn ($p) => $this->formatStagedProduct($p), array_values($allRemaining));

            return ApiResponse::success([
                'remaining_count' => count($cleanRemaining),
                'products' => $cleanRemaining,
            ], "Product {$productId} removed from staged import.");
        } catch (Throwable $e) {
            Log::error('AliExpress remove single staged product failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Edit a product in a staged import before final import (supports multiple image uploads)
     */
    public function updateStagedProduct(Request $request, string $productId): JsonResponse
    {
        try {
            if (in_array(strtolower($productId), ['finalize', 'cancel', 'history', 'import'], true)) {
                return ApiResponse::error("Invalid product ID: {$productId}", 400);
            }

            $user = Auth::guard('jwt')->user();
            $account = AliExpressAccount::byUser($user->id)->active()->latest('connected_at')->first();
            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 422);
            }

            // ─── Process Image Uploads ─────────────────────────────────────────
            $newUploadedImages = [];

            // 1. Multiple image files upload: images, images[], product_images, product_images[]
            $multiFiles = $request->file('images') ?? $request->file('product_images');
            if ($multiFiles) {
                if (! is_array($multiFiles)) {
                    $multiFiles = [$multiFiles];
                }
                foreach ($multiFiles as $file) {
                    if ($file instanceof UploadedFile && $file->isValid()) {
                        $newUploadedImages[] = app(ImageManager::class)->upload($file, 'products');
                    }
                }
            }

            // 2. Single image file upload: image_url or image
            $singleFile = $request->file('image_url') ?? $request->file('image');
            $singleImagePath = null;
            if ($singleFile instanceof UploadedFile && $singleFile->isValid()) {
                $singleImagePath = app(ImageManager::class)->upload($singleFile, 'products');
                array_unshift($newUploadedImages, $singleImagePath);
            }

            // 3. String URLs for images / product_images (array, JSON string, or comma-separated)
            $stringImages = $request->input('images') ?? $request->input('product_images');
            if ($stringImages) {
                if (is_string($stringImages)) {
                    $decoded = json_decode($stringImages, true);
                    $stringImages = is_array($decoded) ? $decoded : array_map('trim', explode(',', $stringImages));
                }
                if (is_array($stringImages)) {
                    foreach ($stringImages as $img) {
                        if (is_string($img) && ! empty($img)) {
                            $newUploadedImages[] = $img;
                        }
                    }
                }
            }

            // Also check if 'image' is passed as an array
            if (is_array($request->input('image'))) {
                foreach ($request->input('image') as $img) {
                    if (is_string($img) && ! empty($img)) {
                        $newUploadedImages[] = $img;
                    }
                }
            }

            // Catch literal keys like "image[0]", "images[0]" passed in raw JSON body
            foreach ($request->all() as $key => $val) {
                if (preg_match('/^images?\[\d+\]$/', $key) && is_string($val)) {
                    $newUploadedImages[] = $val;
                }
            }

            // 4. String URL for image_url or image
            if (! $singleImagePath) {
                if ($request->filled('image_url') && is_string($request->input('image_url'))) {
                    $singleImagePath = $request->input('image_url');
                } elseif ($request->filled('image') && is_string($request->input('image'))) {
                    $singleImagePath = $request->input('image');
                } elseif ($request->filled('image.0') && is_string($request->input('image.0'))) {
                    $singleImagePath = $request->input('image.0');
                } else {
                    foreach ($request->all() as $key => $val) {
                        if (($key === 'image[0]' || $key === 'images[0]') && is_string($val)) {
                            $singleImagePath = $val;
                            break;
                        }
                    }
                }
            }

            $newUploadedImages = array_values(array_unique(array_filter($newUploadedImages)));

            // ─── Update in Staged Imports ──────────────────────────────────────
            $stagedImports = AliExpressImport::byAccount($account->id)
                ->where('status', 'staged')
                ->get();

            $found = false;
            $updatedItem = null;

            foreach ($stagedImports as $import) {
                $staged = $import->summary['staged_products'] ?? [];
                foreach ($staged as $index => $item) {
                    $pid = (string) ($item['aliexpress_product_id'] ?? $item['product_id'] ?? '');
                    if ($pid === (string) $productId) {
                        if ($request->filled('title')) {
                            $staged[$index]['title'] = $request->input('title');
                        }
                        if ($request->filled('price')) {
                            $staged[$index]['price'] = (float) $request->input('price');
                        }
                        if ($request->filled('stock') || $request->filled('stock_quantity')) {
                            $stock = (int) ($request->input('stock') ?? $request->input('stock_quantity'));
                            $staged[$index]['stock'] = $stock;
                            $staged[$index]['stock_quantity'] = $stock;
                        }
                        if ($request->filled('category_id')) {
                            $staged[$index]['category_id'] = (int) $request->input('category_id');
                        }
                        if ($request->filled('description')) {
                            $staged[$index]['description'] = AliExpressProductDTO::cleanHtmlDescription($request->input('description'));
                        }
                        if ($request->filled('shipping_cost')) {
                            $cost = (float) $request->input('shipping_cost');
                            $staged[$index]['shipping_cost'] = $cost;
                            $staged[$index]['shipping_fee'] = $cost <= 0 ? 'Free Shipping' : ('$'.number_format($cost, 2));
                            $staged[$index]['is_free_shipping'] = ($cost <= 0);
                            if (isset($staged[$index]['shipping']) && is_array($staged[$index]['shipping'])) {
                                $staged[$index]['shipping']['shipping_cost'] = $cost;
                                $staged[$index]['shipping']['shipping_fee'] = $staged[$index]['shipping_fee'];
                                $staged[$index]['shipping']['is_free_shipping'] = $staged[$index]['is_free_shipping'];
                            }
                        }

                        // Update main image_url if provided
                        if ($singleImagePath) {
                            $staged[$index]['image_url'] = $singleImagePath;
                        }

                        // Update images array
                        if (! empty($newUploadedImages)) {
                            $existing = $staged[$index]['images'] ?? [];
                            if (is_string($existing)) {
                                $existing = json_decode($existing, true) ?: [$existing];
                            }
                            if ($request->boolean('append_images', false)) {
                                $staged[$index]['images'] = array_values(array_unique(array_merge($existing, $newUploadedImages)));
                            } else {
                                $staged[$index]['images'] = $newUploadedImages;
                            }

                            if (empty($staged[$index]['image_url']) && ! empty($staged[$index]['images'])) {
                                $staged[$index]['image_url'] = $staged[$index]['images'][0];
                            }
                        }

                        $updatedItem = $staged[$index];
                        $found = true;
                    }
                }

                if ($found) {
                    $summary = $import->summary ?? [];
                    $summary['staged_products'] = array_values($staged);
                    $import->update(['summary' => $summary]);
                    break;
                }
            }

            // Fallback: check if already committed in products table
            if (! $found) {
                $dbProduct = Product::where('source', 'aliexpress')
                    ->where('aliexpress_product_id', (string) $productId)
                    ->where('seller_id', $account->profile_id)
                    ->first();

                if ($dbProduct) {
                    if ($request->filled('title')) {
                        $dbProduct->title = $request->input('title');
                    }
                    if ($request->filled('price')) {
                        $dbProduct->price = (float) $request->input('price');
                    }
                    if ($request->filled('stock') || $request->filled('stock_quantity')) {
                        $dbProduct->stock_quantity = (int) ($request->input('stock') ?? $request->input('stock_quantity'));
                    }
                    if ($request->filled('category_id')) {
                        $dbProduct->category_id = (int) $request->input('category_id');
                    }
                    if ($request->filled('description')) {
                        $dbProduct->description = AliExpressProductDTO::cleanHtmlDescription($request->input('description'));
                    }
                    if ($request->filled('shipping_cost')) {
                        $dbProduct->shipping_cost = (float) $request->input('shipping_cost');
                    }
                    if ($singleImagePath) {
                        $dbProduct->image_url = $singleImagePath;
                    }
                    if (! empty($newUploadedImages)) {
                        $existing = is_array($dbProduct->images) ? $dbProduct->images : [];
                        if ($request->boolean('append_images', false)) {
                            $dbProduct->images = array_values(array_unique(array_merge($existing, $newUploadedImages)));
                        } else {
                            $dbProduct->images = $newUploadedImages;
                        }
                        if (empty($dbProduct->image_url) && ! empty($dbProduct->images)) {
                            $dbProduct->image_url = $dbProduct->images[0];
                        }
                    }
                    $dbProduct->save();

                    $updatedItem = [
                        'aliexpress_product_id' => (string) $dbProduct->aliexpress_product_id,
                        'title' => $dbProduct->title,
                        'description' => $dbProduct->description,
                        'price' => (float) $dbProduct->price,
                        'currency' => $dbProduct->currency ?? 'USD',
                        'image_url' => $dbProduct->image_url,
                        'images' => $dbProduct->images ?? [],
                        'stock_quantity' => (int) $dbProduct->stock_quantity,
                        'category_id' => $dbProduct->category_id,
                        'shipping_cost' => (float) ($dbProduct->shipping_cost ?? 0),
                        'is_active' => (bool) $dbProduct->is_active,
                    ];
                    $found = true;
                }
            }

            if (! $found) {
                return ApiResponse::error("Product {$productId} not found in staged imports.", 404);
            }

            return ApiResponse::success([
                'product' => $this->formatStagedProduct($updatedItem),
            ], 'Staged product updated.');
        } catch (Throwable $e) {
            Log::error('AliExpress update staged product failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Final Import Endpoint
     *
     * Commits all staged products into the database products table.
     * All products are stored as is_active = false (pending by default).
     * Downloads/stores images automatically.
     * Clears the staged products list once finalized.
     */
    public function finalize(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            $account = AliExpressAccount::byUser($user->id)->active()->latest('connected_at')->first();
            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 422);
            }

            $stagedImports = AliExpressImport::byAccount($account->id)
                ->where('status', 'staged')
                ->get();

            $allStaged = [];
            foreach ($stagedImports as $imp) {
                foreach ($imp->summary['staged_products'] ?? [] as $p) {
                    $pid = (string) ($p['aliexpress_product_id'] ?? $p['product_id'] ?? '');
                    if ($pid !== '') {
                        $allStaged[$pid] = $p;
                    }
                }
            }

            $requestedIds = $request->input('selected_product_ids') ?? $request->input('product_ids');
            if (! empty($requestedIds) && is_array($requestedIds)) {
                $requestedIds = array_map('strval', $requestedIds);
                $allStaged = array_filter($allStaged, fn ($k) => in_array((string) $k, $requestedIds, true), ARRAY_FILTER_USE_KEY);
            }

            $staged = array_values($allStaged);
            if (empty($staged)) {
                return ApiResponse::error('No staged products found to import.', 422);
            }

            $profile = $user->profile;
            if (! $profile) {
                return ApiResponse::error('Profile not found.', 422);
            }

            // Target the latest staged import for logging
            $targetImport = $stagedImports->last() ?? $stagedImports->first();

            $result = $this->importService->importStagedProducts(
                $profile->id,
                $staged,
                $targetImport,
            );

            // Clear staged products list on finalize across all staged imports
            foreach ($stagedImports as $imp) {
                $summary = $imp->summary ?? [];
                $summary['staged_products'] = [];

                $imp->update([
                    'status' => 'completed',
                    'imported_count' => $imp->id === $targetImport->id ? $result['imported'] : 0,
                    'skipped_count' => $imp->id === $targetImport->id ? $result['skipped'] : 0,
                    'total_processed' => count($staged),
                    'completed_at' => now(),
                    'summary' => $summary,
                ]);
            }

            $account->update(['last_sync_at' => now()]);

            $cleanRecords = array_map(function ($r) {
                return [
                    'product_id' => $r['product_id'],
                    'aliexpress_product_id' => (string) $r['aliexpress_product_id'],
                    'title' => $r['title'],
                    'price' => (float) $r['price'],
                    'is_active' => false,
                ];
            }, $result['records']);

            return ApiResponse::success([
                'status' => 'completed',
                'imported_count' => $result['imported'],
                'products' => $cleanRecords,
            ], "{$result['imported']} product(s) stored in database as pending (is_active: false).");
        } catch (Throwable $e) {
            Log::error('AliExpress finalize import failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * Cancel Import Run
     */
    public function cancel(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            $account = AliExpressAccount::byUser($user->id)->active()->latest('connected_at')->first();
            if (! $account) {
                return ApiResponse::error('No connected AliExpress account found.', 422);
            }

            $stagedImports = AliExpressImport::byAccount($account->id)
                ->where('status', 'staged')
                ->get();

            foreach ($stagedImports as $import) {
                $summary = $import->summary ?? [];
                $summary['staged_products'] = [];

                $import->update([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                    'summary' => $summary,
                    'total_products' => 0,
                ]);
            }

            return ApiResponse::success([
                'status' => 'cancelled',
                'total_products' => 0,
                'products' => [],
            ], 'Import session cancelled and staged items cleared.');
        } catch (Throwable $e) {
            Log::error('AliExpress import cancel failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Get Imported Products
     */
    public function importedProducts(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            $profile = $user->profile;

            if (! $profile) {
                return ApiResponse::error('Profile not found.', 422);
            }

            $perPage = min((int) $request->input('per_page', 20), 100);

            $query = Product::source('aliexpress')
                ->bySeller($profile->id)
                ->latestFirst();

            if ($request->has('active') && $request->input('active') !== '') {
                $query->where('is_active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
            } elseif ($request->has('inactive') && $request->input('inactive') !== '') {
                $query->where('is_active', ! filter_var($request->input('inactive'), FILTER_VALIDATE_BOOLEAN));
            } elseif ($request->filled('status')) {
                $status = strtolower($request->input('status'));
                if ($status === 'active') {
                    $query->where('is_active', true);
                } elseif ($status === 'inactive') {
                    $query->where('is_active', false);
                }
            } elseif ($request->filled('is_active')) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('id')) {
                $id = $request->input('id');
                $query->where(function ($q) use ($id) {
                    $q->where('id', $id)
                      ->orWhere('aliexpress_product_id', (string) $id);
                });
            }

            if ($request->filled('name')) {
                $query->where('title', 'like', '%'.$request->input('name').'%');
            } elseif ($request->filled('search')) {
                $query->where('title', 'like', '%'.$request->input('search').'%');
            }

            $products = $query->paginate($perPage);

            return ApiResponse::paginated(
                $products,
                $products->getCollection()->map(fn ($p) => $this->toAliExpressFormat($p))->values(),
                'Imported AliExpress products retrieved.'
            );
        } catch (Throwable $e) {
            Log::error('AliExpress imported products failed: '.$e->getMessage());

            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    private function toAliExpressFormat(Product $product): array
    {
        $src = $product->aliexpress_source_data ?? [];

        return [
            'id' => $product->id,
            'product_id' => $product->aliexpress_product_id,
            'local_id' => $product->id,
            'title' => $product->title,
            'name' => $product->title,
            'image' => $product->image_url,
            'price_min' => $src['price_min'] ?? $product->price,
            'price_max' => $src['price_max'] ?? $product->price,
            'price' => (float) $product->price,
            'currency' => $product->currency ?? 'USD',
            'product_url' => $product->aliexpress_product_url,
            'stock' => (int) ($product->stock_quantity ?? 0),
            'orders' => (int) ($src['orders'] ?? 0),
            'rating' => $src['rating'] ?? null,
            'is_active' => (bool) $product->is_active,
            'status' => $product->is_active ? 'active' : 'inactive',
        ];
    }

    /**
     * Format a staged product record ensuring full alignment with authentic AliExpress storefront product data.
     */
    protected function formatStagedProduct(array $p): array
    {
        $pid = (string) ($p['product_id'] ?? $p['aliexpress_product_id'] ?? '');
        $price = (float) ($p['price'] ?? $p['price_min'] ?? 0);
        $priceMin = (string) ($p['price_min'] ?? ($price > 0 ? number_format($price, 2, '.', '') : '0.00'));
        $priceMax = (string) ($p['price_max'] ?? $priceMin);
        $origMin = (string) ($p['original_price_min'] ?? $p['original_price'] ?? $priceMin);
        $origMax = (string) ($p['original_price_max'] ?? $origMin);
        $stock = (int) ($p['stock'] ?? $p['stock_quantity'] ?? 100);
        $orders = (int) ($p['orders'] ?? 0);
        $sold = (string) ($p['sold'] ?? $p['sold_desc'] ?? ($orders > 0 ? ($orders >= 1000 ? number_format((int) (floor($orders / 1000) * 1000)).'+ sold' : $orders.' sold') : '0 sold'));
        $reviewsCount = (int) ($p['reviews_count'] ?? $p['reviews'] ?? 0);
        $reviewsDesc = (string) ($p['reviews_desc'] ?? ($reviewsCount > 0 ? number_format($reviewsCount).' Reviews' : '0 Reviews'));
        $rating = (string) ($p['rating'] ?? '');
        $mainImg = $p['image'] ?? $p['image_url'] ?? null;
        if (is_string($mainImg)) {
            $mainImg = ImageManager::fileUrl($mainImg);
        }
        $rawImages = $p['images'] ?? ($mainImg ? [$mainImg] : []);
        if (is_string($rawImages)) {
            $rawImages = json_decode($rawImages, true) ?: [$rawImages];
        }
        $formattedImages = array_map(fn ($img) => ImageManager::fileUrl($img), is_array($rawImages) ? $rawImages : []);

        $shippingCost = (float) ($p['shipping_cost'] ?? 0);
        $shippingFee = (string) ($p['shipping_fee'] ?? ($shippingCost <= 0 ? 'Free Shipping' : ('$'.number_format($shippingCost, 2))));
        $isFreeShipping = isset($p['is_free_shipping']) ? (bool) $p['is_free_shipping'] : ($shippingCost <= 0);
        $carrier = (string) ($p['carrier'] ?? 'AliExpress Standard Shipping');

        $shipping = $this->normalizeShipping(
            $p['shipping'] ?? null,
            (string) ($p['ship_to_country'] ?? 'US'),
            $shippingCost,
            $carrier,
            (string) ($p['currency'] ?? 'USD')
        );

        // Build clean product_variants: only display-relevant fields
        $skus = $p['skus'] ?? [];
        $rawVariants = $p['product_variants'] ?? [];
        if (empty($rawVariants) && ! empty($skus)) {
            $rawVariants = array_map(fn ($s) => [
                'sku' => $s['sku_code'] ?? $s['sku_attr'] ?? '',
                'image' => $s['image'] ?? null,
                'price' => $s['price'] ?? '0.00',
                'stock' => (int) ($s['stock'] ?? 0),
                'options' => [],
                'original_price' => $s['original_price'] ?? null,
            ], $skus);
        }
        // Sync stock from skus where variant stock is 0
        if (! empty($skus) && ! empty($rawVariants)) {
            $skuStockMap = [];
            foreach ($skus as $s) {
                $code = $s['sku_code'] ?? $s['sku_attr'] ?? '';
                if ($code !== '') {
                    $skuStockMap[$code] = (int) ($s['stock'] ?? 0);
                }
            }
            foreach ($rawVariants as &$v) {
                $vCode = $v['sku'] ?? $v['sku_code'] ?? $v['sku_attr'] ?? '';
                if (isset($skuStockMap[$vCode]) && (int) ($v['stock'] ?? 0) === 0 && $skuStockMap[$vCode] > 0) {
                    $v['stock'] = $skuStockMap[$vCode];
                }
            }
            unset($v);
        }
        $variants = array_map(fn ($v) => [
            'sku' => $v['sku'] ?? $v['sku_code'] ?? '',
            'image' => $v['image'] ?? null,
            'price' => $v['price'] ?? '0.00',
            'stock' => (int) ($v['stock'] ?? $v['stockQuantity'] ?? 0),
            'options' => $v['options'] ?? [],
            'original_price' => $v['original_price'] ?? null,
        ], $rawVariants);

        return [
            'product_id' => $pid,
            'aliexpress_product_id' => $pid,
            'title' => (string) ($p['title'] ?? ''),
            'description' => AliExpressProductDTO::cleanHtmlDescription($p['description'] ?? null),
            'price' => $price,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'original_price' => $origMin,
            'original_price_max' => $origMax,
            'supplier_cost' => (float) ($p['supplier_cost'] ?? $price),
            'currency' => (string) ($p['currency'] ?? 'USD'),
            'image_url' => $mainImg,
            'images' => $formattedImages,
            'stock' => $stock,
            'in_stock' => $stock > 0,
            'orders' => $orders,
            'sold' => $sold,
            'rating' => $rating,
            'reviews' => $reviewsCount,
            'reviews_desc' => $reviewsDesc,
            'category_id' => $p['category_id'] ?? null,
            'category_name' => $p['category_name'] ?? null,
            'shipping_cost' => $shippingCost,
            'shipping_fee' => $shippingFee,
            'is_free_shipping' => $isFreeShipping,
            'carrier' => $carrier,
            'shipping' => $shipping,
            'product_url' => $p['product_url'] ?? ($pid !== '' ? "https://www.aliexpress.com/item/{$pid}.html" : null),
            'ship_to_country' => $p['ship_to_country'] ?? 'US',
            'is_active' => (bool) ($p['is_active'] ?? false),
            'product_variants' => $variants,
        ];
    }

    /**
     * Normalize shipping data structure for API response.
     *
     * @return array{ship_to_country: string, shipping_options: array<int, array<string, mixed>>}
     */
    protected function normalizeShipping(
        mixed $shipping,
        string $shipToCountry = 'US',
        float $shippingCost = 0.0,
        string $carrier = 'AliExpress Standard Shipping',
        string $currency = 'USD'
    ): array {
        if (is_string($shipping)) {
            $decoded = json_decode($shipping, true);
            $shipping = is_array($decoded) ? $decoded : null;
        }

        if (empty($shipping) || empty($shipping['shipping_options']) || ! is_array($shipping['shipping_options'])) {
            $cost = isset($shipping['shipping_cost']) ? (float) $shipping['shipping_cost'] : $shippingCost;
            $c = ! empty($shipping['carrier']) ? (string) $shipping['carrier'] : $carrier;
            $curr = ! empty($shipping['currency']) ? (string) $shipping['currency'] : $currency;
            $isFree = isset($shipping['is_free_shipping'])
                ? (bool) $shipping['is_free_shipping']
                : ($cost <= 0);

            return [
                'ship_to_country' => ! empty($shipping['ship_to_country']) ? (string) $shipping['ship_to_country'] : $shipToCountry,
                'shipping_options' => [
                    [
                        'name' => $c,
                        'carrier' => $c,
                        'cost' => $cost,
                        'currency' => $curr,
                        'is_free' => $isFree,
                        'delivery_time' => $shipping['delivery_time'] ?? '10-15 business days',
                        'estimated_delivery' => $shipping['estimated_delivery'] ?? (now()->addDays(10)->format('M d').' - '.now()->addDays(18)->format('M d')),
                        'ship_from_country' => (string) ($shipping['ship_from_country'] ?? 'CN'),
                        'tracking_supported' => (bool) ($shipping['tracking_available'] ?? $shipping['tracking_supported'] ?? true),
                    ],
                ],
            ];
        }

        $options = $shipping['shipping_options'];
        if (is_string($options)) {
            $decodedOptions = json_decode($options, true);
            $options = is_array($decodedOptions) ? $decodedOptions : [];
        }

        return [
            'ship_to_country' => (string) ($shipping['ship_to_country'] ?? $shipToCountry),
            'shipping_options' => array_values(array_map(fn ($opt) => [
                'name' => (string) ($opt['name'] ?? $opt['carrier'] ?? $carrier),
                'carrier' => (string) ($opt['carrier'] ?? $carrier),
                'cost' => (float) ($opt['cost'] ?? $opt['price'] ?? 0),
                'currency' => (string) ($opt['currency'] ?? $currency),
                'is_free' => (bool) ($opt['is_free'] ?? (($opt['cost'] ?? $opt['price'] ?? 0) <= 0)),
                'delivery_time' => $opt['delivery_time'] ?? $opt['commit_day'] ?? null,
                'estimated_delivery' => $opt['estimated_delivery'] ?? null,
                'ship_from_country' => (string) ($opt['ship_from_country'] ?? 'CN'),
                'tracking_supported' => (bool) ($opt['tracking_supported'] ?? true),
            ], array_filter((array) $options, 'is_array'))),
        ];
    }

    /**
     * Enrich a staged product with authentic live AliExpress product details if needed.
     */
    protected function enrichStagedProductIfIncomplete(array $p, AliExpressAccount $account, string $effectiveCountry = 'US'): array
    {
        $pid = (string) ($p['aliexpress_product_id'] ?? $p['product_id'] ?? '');
        if ($pid === '') {
            return $p;
        }

        $currentStock = (int) ($p['stock_quantity'] ?? $p['stock'] ?? 0);
        $hasRating = ! empty($p['rating']);
        $hasImages = ! empty($p['images']) && is_array($p['images']) && count($p['images']) > 1;
        $hasShipping = ! empty($p['shipping']) && isset($p['shipping_fee']);

        if ($currentStock === 0 || ! $hasRating || ! $hasImages || ! $hasShipping) {
            try {
                $detailDto = $this->productClient->detail($account, $effectiveCountry, $pid);
                if ($detailDto) {
                    $skuOrig = array_filter(array_map(
                        fn ($s) => ! empty($s->originalPrice) ? (float) $s->originalPrice : null,
                        $detailDto->skus ?? []
                    ));
                    $cost = $detailDto->costPrice();
                    $pOrigMin = $skuOrig ? min($skuOrig) : (float) ($detailDto->priceMin ?? $cost);
                    $pOrigMax = $skuOrig ? max($skuOrig) : (float) ($detailDto->priceMax ?? $cost);
                    $pOrders = (int) ($detailDto->orders ?? 0);
                    $pSold = $detailDto->sold ?? ($pOrders > 0 ? ($pOrders >= 1000 ? number_format((int) (floor($pOrders / 1000) * 1000)).'+ sold' : $pOrders.' sold') : '0 sold');
                    $pRevCount = (int) ($detailDto->reviews ?? 0);
                    $pRevDesc = $detailDto->reviewsDesc ?? ($pRevCount > 0 ? number_format($pRevCount).' Reviews' : '0 Reviews');
                    $pStock = (int) ($detailDto->totalStock > 0 ? $detailDto->totalStock : 100);
                    $pMainImg = $detailDto->mainImage ?? ($detailDto->images[0] ?? ($p['image_url'] ?? null));

                    if (empty($p['title']) || str_starts_with($p['title'], 'AliExpress Product ')) {
                        $p['title'] = $detailDto->subject ?: $p['title'];
                    }
                    if (! empty($detailDto->description)) {
                        $p['description'] = AliExpressProductDTO::cleanHtmlDescription($detailDto->description);
                    } elseif (! empty($p['description'])) {
                        $p['description'] = AliExpressProductDTO::cleanHtmlDescription($p['description']);
                    }
                    if (empty($p['price']) || (float) $p['price'] <= 0) {
                        $p['price'] = (float) ($detailDto->priceMin ?? $cost);
                    }
                    $p['price_min'] = (string) ($detailDto->priceMin ?? $p['price']);
                    $p['price_max'] = (string) ($detailDto->priceMax ?? $p['price']);
                    $p['original_price'] = (string) $pOrigMin;
                    $p['original_price_min'] = (string) $pOrigMin;
                    $p['original_price_max'] = (string) $pOrigMax;
                    $p['supplier_cost'] = (float) ($p['supplier_cost'] ?? $cost);
                    $p['currency'] = (string) ($detailDto->currency ?? $p['currency'] ?? 'USD');
                    $p['stock'] = $pStock;
                    $p['stock_quantity'] = $pStock;
                    $p['in_stock'] = true;
                    $p['orders'] = $pOrders;
                    $p['sold'] = $pSold;
                    $p['sold_desc'] = $pSold;
                    $p['rating'] = (string) ($detailDto->rating ?? '');
                    $p['reviews'] = $pRevCount;
                    $p['reviews_count'] = $pRevCount;
                    $p['reviews_desc'] = $pRevDesc;
                    $p['image'] = $pMainImg;
                    $p['image_url'] = $pMainImg;
                    $p['images'] = $detailDto->images ?: ($pMainImg ? [$pMainImg] : []);
                    $p['videos'] = $detailDto->videos ?: [];
                    $p['product_url'] = $detailDto->productUrl ?? "https://www.aliexpress.com/item/{$pid}.html";
                    $p['ship_to_country'] = $effectiveCountry;

                    // Shipping, package & logistics enrichment
                    $shippingData = $detailDto->shipping ?? $this->productClient->calculateShippingDetails($account, $effectiveCountry, $pid, $detailDto);
                    $p['shipping'] = $shippingData;
                    $p['shipping_cost'] = (float) ($shippingData['shipping_cost'] ?? 0);
                    $p['shipping_fee'] = (string) ($shippingData['shipping_fee'] ?? 'Free Shipping');
                    $p['is_free_shipping'] = (bool) ($shippingData['is_free_shipping'] ?? true);
                    $p['carrier'] = (string) ($shippingData['carrier'] ?? 'AliExpress Standard Shipping');
                    $p['package'] = $detailDto->package ?? $shippingData['package'] ?? null;
                    $p['logistics'] = $detailDto->logistics ?? $shippingData['logistics'] ?? null;

                    if (empty($p['category_name']) && ! empty($detailDto->categoryName)) {
                        $p['category_name'] = $detailDto->categoryName;
                    }
                    if (! empty($detailDto->skus)) {
                        $p['product_variants'] = array_map(fn ($sku) => $sku->toLocalVariant(), $detailDto->skus);
                        $p['skus'] = array_map(fn ($sku) => [
                            'sku_id' => (string) $sku->skuId,
                            'sku_code' => $sku->skuCode,
                            'sku_attr' => $sku->skuAttr,
                            'price' => (string) ($sku->skuPrice ?? '0.00'),
                            'original_price' => (string) ($sku->originalPrice ?? $sku->skuPrice ?? '0.00'),
                            'currency' => (string) ($sku->currency ?? $detailDto->currency ?? 'USD'),
                            'stock' => (int) ($sku->stock > 0 ? $sku->stock : 10),
                            'image' => $sku->image ?? null,
                            'properties' => $sku->properties ?? [],
                        ], $detailDto->skus);
                    }
                }
            } catch (Throwable $e) {
                Log::info("AliExpress staged enrichment skipped for {$pid}: ".$e->getMessage());
            }
        }

        // Ensure shipping is always populated with authentic details
        if (empty($p['shipping']) || empty($p['shipping_fee'])) {
            try {
                $shippingData = $this->productClient->calculateShippingDetails($account, $effectiveCountry, $pid, null);
                $p['shipping'] = $shippingData;
                $p['shipping_cost'] = (float) ($shippingData['shipping_cost'] ?? 0);
                $p['shipping_fee'] = (string) ($shippingData['shipping_fee'] ?? 'Free Shipping');
                $p['is_free_shipping'] = (bool) ($shippingData['is_free_shipping'] ?? true);
                $p['carrier'] = (string) ($shippingData['carrier'] ?? 'AliExpress Standard Shipping');
                $p['package'] = $p['package'] ?? $shippingData['package'] ?? null;
                $p['logistics'] = $p['logistics'] ?? $shippingData['logistics'] ?? null;
            } catch (Throwable $e) {
                $cost = (float) ($p['shipping_cost'] ?? 0);
                $isFree = ($cost <= 0);
                $p['shipping_cost'] = $cost;
                $p['shipping_fee'] = $isFree ? 'Free Shipping' : ('$'.number_format($cost, 2));
                $p['is_free_shipping'] = $isFree;
                $p['carrier'] = 'AliExpress Standard Shipping';
                $p['shipping'] = [
                    'shipping_cost' => $cost,
                    'shipping_fee' => $p['shipping_fee'],
                    'is_free_shipping' => $isFree,
                    'carrier' => $p['carrier'],
                    'shipping_method' => $p['carrier'],
                    'delivery_time' => '10-15 business days',
                    'estimated_delivery' => now()->addDays(10)->format('M d').' - '.now()->addDays(18)->format('M d'),
                    'tracking_available' => true,
                    'ship_from_country' => 'CN',
                    'ship_to_country' => $effectiveCountry,
                    'shipping_options' => [
                        [
                            'id' => 'standard_shipping',
                            'name' => 'AliExpress Standard Shipping',
                            'cost' => $cost,
                            'carrier' => 'AliExpress Standard Shipping',
                            'currency' => (string) ($p['currency'] ?? 'USD'),
                            'estimated_delivery' => '10-15 business days',
                            'tracking_supported' => true,
                            'is_free' => $isFree,
                        ],
                    ],
                    'package' => $p['package'] ?? null,
                    'logistics' => $p['logistics'] ?? null,
                ];
            }
        }

        return $p;
    }
}
