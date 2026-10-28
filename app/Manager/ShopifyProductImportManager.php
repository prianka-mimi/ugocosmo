<?php

namespace App\Manager;

use App\Models\Product;
use App\Models\ShopifyStore;
use App\Services\ShopifyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopifyProductImportManager
{
    protected ShopifyService $shopifyService;

    protected ImageManager $imageManager;

    protected ProductManager $productManager;

    public function __construct(
        ShopifyService $shopifyService,
        ImageManager $imageManager,
        ProductManager $productManager
    ) {
        $this->shopifyService = $shopifyService;
        $this->imageManager = $imageManager;
        $this->productManager = $productManager;
    }

    /**
     * Fetch products from a Shopify store (preview before import).
     */
    public function preview(ShopifyStore $store, array $params = []): array
    {
        $this->shopifyService->forStore($store);

        $limit = (int) ($params['limit'] ?? 10);
        $sinceId = $params['since_id'] ?? null;

        $result = $this->shopifyService->getProducts($limit, $sinceId);

        return [
            'store' => [
                'id' => $store->id,
                'store_domain' => $store->store_domain,
            ],
            'products' => $result['products'],
            'next_page' => $result['next_page'],
            'total_count' => $this->shopifyService->countProducts(),
            'fetched_count' => count($result['products']),
        ];
    }

    /**
     * Import products from a Shopify store into the local database.
     */
    public function import(ShopifyStore $store, array $params = []): array
    {
        $this->shopifyService->forStore($store);

        $limit = (int) ($params['limit'] ?? 50);
        $sinceId = $params['since_id'] ?? null;
        $categoryId = $params['category_id'] ?? null;
        $defaultCondition = $params['default_condition'] ?? 'new';
        $defaultCurrency = $params['default_currency'] ?? 'USD';
        $downloadImages = filter_var($params['download_images'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $sellerId = $params['seller_id'] ?? $this->profileId();

        $result = $this->shopifyService->getProducts($limit, $sinceId);
        $shopifyProducts = $result['products'];
        $storeDomain = $store->store_domain;

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($shopifyProducts as $shopifyProduct) {
            try {
                $shopifyId = (string) $shopifyProduct['id'];

                // Skip if product already imported from this store
                $existing = Product::where('shopify_product_id', $shopifyId)
                    ->where('shopify_store_url', $storeDomain)
                    ->first();

                if ($existing) {
                    $skipped++;

                    continue;
                }

                $variant = $shopifyProduct['variants'][0] ?? [];
                $image = $shopifyProduct['image'] ?? null;

                $productData = [
                    'seller_id' => (int) $sellerId,
                    'title' => $shopifyProduct['title'],
                    'description' => strip_tags($shopifyProduct['body_html'] ?? ''),
                    'price' => (float) ($variant['price'] ?? 0),
                    'currency' => $defaultCurrency,
                    'compare_at_price' => ! empty($variant['compare_at_price'])
                        ? (float) $variant['compare_at_price']
                        : null,
                    'category_id' => $categoryId ? (int) $categoryId : null,
                    'stock_quantity' => (int) ($variant['inventory_quantity'] ?? 0),
                    'product_type' => $shopifyProduct['product_type'] ?? null,
                    'condition' => $defaultCondition,
                    'brand' => $shopifyProduct['vendor'] ?? null,
                    'sku' => $variant['sku'] ?? null,
                    'weight' => (float) ($variant['weight'] ?? 0),
                    'is_active' => $shopifyProduct['status'] === 'active',
                    'shopify_product_id' => $shopifyId,
                    'shopify_store_url' => $storeDomain,
                    'shopify_source_data' => [
                        'shopify_id' => $shopifyId,
                        'store_id' => $store->id,
                        'variants' => array_map(fn ($v) => [
                            'id' => $v['id'],
                            'title' => $v['title'],
                            'price' => $v['price'],
                            'sku' => $v['sku'],
                        ], $shopifyProduct['variants'] ?? []),
                        'imported_at' => now()->toIso8601String(),
                    ],
                ];

                $product = Product::create($productData);

                // Download & attach images
                if ($downloadImages && $image && ! empty($image['src'])) {
                    $uploadedPath = $this->downloadAndStoreImage($image['src']);
                    if ($uploadedPath) {
                        $product->image_url = $uploadedPath;
                    }

                    $additionalPaths = [];
                    foreach (array_slice($shopifyProduct['images'] ?? [], 0, 10) as $img) {
                        if (! empty($img['src']) && $img['src'] !== ($image['src'] ?? null)) {
                            $path = $this->downloadAndStoreImage($img['src']);
                            if ($path) {
                                $additionalPaths[] = $path;
                            }
                        }
                    }
                    if (! empty($additionalPaths)) {
                        $product->images = $additionalPaths;
                    }
                    $product->save();
                }

                // Sync tags (reuses ProductManager::syncTags)
                if (! empty($shopifyProduct['tags'])) {
                    $tagNames = array_map('trim', explode(',', $shopifyProduct['tags']));
                    $this->productManager->syncTags($product, $tagNames);
                }

                $imported++;
            } catch (\Exception $e) {
                $errors[] = [
                    'shopify_id' => $shopifyProduct['id'] ?? 'unknown',
                    'title' => $shopifyProduct['title'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
                Log::error('Retrieval Failed: Shopify import product '.($shopifyProduct['id'] ?? 'unknown').': '.$e->getMessage());
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'next_page' => $result['next_page'],
            'total_count' => $this->shopifyService->countProducts(),
            'store_domain' => $storeDomain,
        ];
    }

    /**
     * Download a Shopify image and store it locally via ImageManager.
     */
    protected function downloadAndStoreImage(string $url): ?string
    {
        try {
            $imageContent = $this->shopifyService->downloadImage($url);
            if (! $imageContent) {
                return null;
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'shopify_');
            file_put_contents($tempPath, $imageContent);

            $uploadedPath = $this->imageManager->upload(
                new UploadedFile($tempPath, basename($url)),
                'products'
            );

            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            return $uploadedPath;
        } catch (\Exception $e) {
            Log::error('Retrieval Failed: Shopify image download for '.$url.': '.$e->getMessage());

            return null;
        }
    }

    /**
     * Get import summary for all stores connected by the current user.
     *
     * Uses a short cache (5 minutes) for Shopify API counts to avoid
     * making one HTTP call per store on every dashboard load.
     */
    public function importStatusForProfile(int $profileId): array
    {
        $stores = ShopifyStore::byProfile($profileId)->get();
        $data = [];

        foreach ($stores as $store) {
            $shopifyTotal = Cache::remember(
                "shopify_store_product_count_{$store->id}",
                300, // 5 min cache
                function () use ($store) {
                    $this->shopifyService->forStore($store);

                    return $this->shopifyService->countProducts();
                }
            );

            $data[] = [
                'store_id' => $store->id,
                'store_domain' => $store->store_domain,
                'installed_at' => $store->installed_at?->toIso8601String(),
                'is_configured' => true,
                'imported_count' => Product::where('shopify_store_url', $store->store_domain)->count(),
                'shopify_total_products' => $shopifyTotal,
            ];
        }

        return [
            'stores' => $data,
            'total_stores' => count($data),
            'total_imported' => Product::whereNotNull('shopify_product_id')
                ->whereIn('shopify_store_url', $stores->pluck('store_domain'))
                ->count(),
        ];
    }

    /**
     * Get paginated list of imported Shopify products.
     */
    public function importedProducts(int $perPage = 20)
    {
        return Product::whereNotNull('shopify_product_id')
            ->with(['seller', 'category', 'tags'])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get import history for a specific Shopify store.
     * Derives sessions by grouping imported products by their imported_at timestamp.
     */
    public function importHistory(ShopifyStore $store, int $perPage = 20): array
    {
        $products = Product::where('shopify_store_url', $store->store_domain)
            ->whereNotNull('shopify_product_id')
            ->latest()
            ->paginate($perPage);

        // Group products by approximate import session (hour granularity)
        $sessions = [];
        foreach ($products as $product) {
            $importedAt = $product->shopify_source_data['imported_at'] ?? $product->created_at->toIso8601String();
            $sessionKey = substr($importedAt, 0, 13); // Group by hour

            if (! isset($sessions[$sessionKey])) {
                $sessions[$sessionKey] = [
                    'imported_at' => $importedAt,
                    'products' => [],
                    'count' => 0,
                ];
            }
            $sessions[$sessionKey]['products'][] = [
                'id' => $product->id,
                'title' => $product->title,
                'price' => $product->price,
                'sku' => $product->sku,
                'image_url' => $product->image_url,
                'imported_at' => $importedAt,
            ];
            $sessions[$sessionKey]['count']++;
        }

        return [
            'store' => [
                'id' => $store->id,
                'store_domain' => $store->store_domain,
            ],
            'sessions' => array_values($sessions),
            'total_imported' => Product::where('shopify_store_url', $store->store_domain)
                ->whereNotNull('shopify_product_id')
                ->count(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ],
        ];
    }

    /**
     * Get import progress for a specific import session.
     * For now, returns the latest import details for the store.
     */
    public function importProgress(ShopifyStore $store, int $importId = 0): array
    {
        $this->shopifyService->forStore($store);

        $totalShopify = $this->shopifyService->countProducts();
        $importedCount = Product::where('shopify_store_url', $store->store_domain)
            ->whereNotNull('shopify_product_id')
            ->count();

        $lastProduct = Product::where('shopify_store_url', $store->store_domain)
            ->whereNotNull('shopify_product_id')
            ->latest()
            ->first();

        return [
            'store' => [
                'id' => $store->id,
                'store_domain' => $store->store_domain,
            ],
            'total_shopify_products' => $totalShopify,
            'total_imported' => $importedCount,
            'remaining' => max(0, $totalShopify - $importedCount),
            'progress_percentage' => $totalShopify > 0
                ? round(($importedCount / $totalShopify) * 100, 1)
                : 0,
            'last_imported_at' => $lastProduct?->shopify_source_data['imported_at']
                ?? $lastProduct?->created_at?->toIso8601String()
                ?? null,
            'last_imported_product' => $lastProduct ? [
                'id' => $lastProduct->id,
                'title' => $lastProduct->title,
            ] : null,
        ];
    }

    /**
     * Get the authenticated profile ID.
     */
    protected function profileId(): int
    {
        foreach (['web', 'jwt'] as $guard) {
            $user = Auth::guard($guard)->user();
            if ($user) {
                $profile = $user->profile ?? null;
                if ($profile) {
                    return (int) $profile->id;
                }
            }
        }
        throw new \Exception('Profile not found. Complete your profile first.', 400);
    }
}
