<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShopifyConnectRequest;
use App\Http\Requests\ShopifySelectImportRequest;
use App\Http\Resources\ShopifyImportResource;
use App\Http\Resources\ShopifyProductPreviewResource;
use App\Http\Resources\ShopifyStoreHealthResource;
use App\Http\Resources\ShopifyStoreResource;
use App\Jobs\ShopifyProductImportJob;
use App\Manager\ShopifyProductImportManager;
use App\Manager\ShopifySyncManager;
use App\Manager\ShopifyWebhookManager;
use App\Models\Profile;
use App\Models\ShopifyStore;
use App\Models\ShopifyWebhookCall;
use App\Models\User;
use App\Repositories\ShopifyImportRepository;
use App\Repositories\ShopifyStoreRepository;
use App\Services\ShopifyGraphQLService;
use App\Services\ShopifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Throwable;

class ShopifyFlutterController extends Controller
{
    public function __construct(
        protected ShopifyService $shopifyService,
        protected ShopifyGraphQLService $graphQL,
        protected ShopifyProductImportManager $importManager,
        protected ShopifySyncManager $syncManager,
        protected ShopifyStoreRepository $storeRepository,
        protected ShopifyImportRepository $importRepository,
    ) {}

    /**
     * Step 1: Generate Shopify OAuth authorization URL.
     *
     * POST /api/shopify/connect
     * Body: { "shop": "your-store" }
     *
     * Flutter: Open authorization_url in browser/WebView.
     */
    public function connect(ShopifyConnectRequest $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $shop = $request->input('shop');
            $storeDomain = $this->shopifyService->normalizeDomain($shop);

            // Check if already connected
            if ($this->storeRepository->existsForUser($user->id, $storeDomain)) {
                return $this->success([
                    'store_domain' => $storeDomain,
                    'authorization_url' => null,
                    'already_connected' => true,
                ], 'This store is already connected. Reconnect to update the token.');
            }

            // Generate state for CSRF protection
            $state = $this->shopifyService->generateState();

            // Store state in session/link to user
            Session::put('shopify_oauth_state', $state);
            Session::put('shopify_oauth_user_id', $user->id);
            Session::put('shopify_oauth_store', $storeDomain);

            $authUrl = $this->shopifyService->buildAuthorizationUrl($storeDomain, $state);

            return $this->success([
                'store_domain' => $storeDomain,
                'authorization_url' => $authUrl,
                'already_connected' => false,
            ], 'Shopify OAuth authorization URL generated successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify connect failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Step 2: Handle OAuth callback (web route - Shopify redirects browser here).
     *
     * GET /auth/shopify/callback (web.php)
     * After completing OAuth, returns JSON for Flutter to capture via deep link.
     */
    public function callback(Request $request): JsonResponse
    {
        $shop = $request->input('shop');
        $code = $request->input('code');
        $state = $request->input('state');
        $hmac = $request->input('hmac');

        Log::debug('Shopify OAuth callback received', [
            'shop' => $shop,
            'has_code' => ! empty($code),
            'has_state' => ! empty($state),
            'has_hmac' => ! empty($hmac),
        ]);

        try {
            if (! $shop || ! $code || ! $hmac) {
                Log::error('Shopify callback: missing required params', [
                    'shop' => $shop ?? 'null',
                    'code' => ! empty($code) ? 'present' : 'missing',
                    'hmac' => ! empty($hmac) ? 'present' : 'missing',
                ]);

                return $this->error('Missing required OAuth parameters (shop, code, hmac).', 400);
            }

            // Verify HMAC
            $params = $request->only(['code', 'host', 'shop', 'state', 'timestamp']);
            if (! $this->shopifyService->verifyHmac($params)) {
                Log::error('Shopify callback: HMAC verification failed', ['shop' => $shop]);

                return $this->error('HMAC verification failed. Invalid OAuth response from Shopify.', 400);
            }
            Log::debug('Shopify callback: HMAC verified', ['shop' => $shop]);

            // Verify state (CSRF)
            $savedState = Session::get('shopify_oauth_state');
            Log::debug('Shopify callback: checking state', [
                'saved_state_exists' => ! empty($savedState),
                'state_matches' => $savedState === $state,
            ]);
            if ($savedState && $savedState !== $state) {
                Log::error('Shopify callback: state mismatch', ['shop' => $shop]);

                return $this->error('State mismatch. Possible CSRF attack.', 400);
            }

            // Resolve user from session
            $userId = Session::get('shopify_oauth_user_id');
            Log::debug('Shopify callback: resolving user', [
                'user_id_from_session' => $userId,
            ]);
            if (! $userId) {
                Log::error('Shopify callback: no user_id in session. Auth session may have expired.');

                return $this->error('Authentication required. Session expired.', 401);
            }

            // Exchange code for token
            Log::info('Shopify callback: exchanging code for token', ['shop' => $shop]);
            $tokenData = $this->shopifyService->exchangeCode($shop, $code);
            if (! $tokenData || empty($tokenData['access_token'])) {
                Log::error('Shopify callback: token exchange failed', [
                    'shop' => $shop,
                    'tokenData' => $tokenData,
                ]);

                return $this->error('Failed to exchange authorization code for access token.', 400);
            }
            Log::info('Shopify callback: token exchange succeeded', ['shop' => $shop]);

            $storeDomain = $this->shopifyService->normalizeDomain($shop);

            // Get store information
            $shopInfo = $this->fetchShopInfo($storeDomain, $tokenData['access_token']);
            Log::debug('Shopify callback: shop info fetched', [
                'shop' => $shop,
                'shop_name' => $shopInfo['name'] ?? 'unknown',
            ]);

            // ─── Token refresh: use stored store ID if available ──────
            $existingStoreId = Session::get('shopify_oauth_store_id');
            $store = null;

            if ($existingStoreId) {
                $store = ShopifyStore::withTrashed()->find($existingStoreId);
                Log::debug('Shopify callback: found store by session store_id', [
                    'store_id' => $existingStoreId,
                    'found' => ! empty($store),
                ]);
            }

            if (! $store) {
                $store = $this->storeRepository->findByDomain($userId, $storeDomain);
                Log::debug('Shopify callback: found store by domain', [
                    'domain' => $storeDomain,
                    'found' => ! empty($store),
                ]);
            }

            if ($store) {
                // Reconnect — update token (with trashed restore)
                Log::info('Shopify callback: reconnecting existing store', [
                    'store_id' => $store->id,
                    'store' => $storeDomain,
                ]);
                $store = $this->storeRepository->update($store, [
                    'access_token' => $tokenData['access_token'],
                    'scopes' => $tokenData['scope'] ?? $this->shopifyService->getScopes(),
                    'installed_at' => now(),
                    'status' => 'active',
                    'deleted_at' => null, // Re-activate soft-deleted
                    'shop_name' => $shopInfo['name'] ?? null,
                    'currency' => $shopInfo['currency'] ?? null,
                    'country' => $shopInfo['country_name'] ?? null,
                    'timezone' => $shopInfo['timezone'] ?? null,
                ]);
            } else {
                // New connection
                $profile = $this->resolveProfile($userId);
                Log::info('Shopify callback: creating new store', [
                    'user_id' => $userId,
                    'profile_id' => $profile?->id,
                    'store' => $storeDomain,
                ]);
                $store = $this->storeRepository->create([
                    'profile_id' => $profile?->id,
                    'user_id' => $userId,
                    'store_domain' => $storeDomain,
                    'access_token' => $tokenData['access_token'],
                    'scopes' => $tokenData['scope'] ?? $this->shopifyService->getScopes(),
                    'installed_at' => now(),
                    'status' => 'active',
                    'shop_name' => $shopInfo['name'] ?? null,
                    'currency' => $shopInfo['currency'] ?? null,
                    'country' => $shopInfo['country_name'] ?? null,
                    'timezone' => $shopInfo['timezone'] ?? null,
                ]);
            }

            // Register webhooks
            $this->registerWebhooksForStore($store);

            // Clear session
            Session::forget([
                'shopify_oauth_state', 'shopify_oauth_user_id', 'shopify_oauth_store',
                'shopify_oauth_store_id', 'shopify_oauth_reconnect',
            ]);

            Log::info('Shopify store connected successfully', [
                'store_id' => $store->id,
                'store' => $storeDomain,
                'user_id' => $userId,
            ]);

            return $this->success([
                'store' => new ShopifyStoreResource($store->fresh()),
            ], 'Shopify store connected successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify callback failed: '.$e->getMessage(), [
                'shop' => $shop,
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * List all connected stores for the authenticated user.
     *
     * GET /api/shopify/stores
     */
    public function stores(): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $stores = $this->storeRepository->getByUser($user->id);

            return $this->success([
                'stores' => ShopifyStoreResource::collection($stores),
                'total' => $stores->count(),
            ], 'Connected stores retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify stores list failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Check if user has any connected Shopify stores.
     *
     * GET /api/shopify/stores/check
     */
    public function checkStore(): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $stores = $this->storeRepository->getByUser($user->id);

            Log::debug('Shopify checkStore', [
                'user_id' => $user->id,
                'stores_found' => $stores->count(),
                'connected' => $stores->isNotEmpty(),
            ]);

            return $this->success([
                'connected' => $stores->isNotEmpty(),
                'store' => $stores->isNotEmpty() ? new ShopifyStoreResource($stores->first()) : null,
                'total_stores' => $stores->count(),
            ], 'Store connection status retrieved.');
        } catch (Throwable $e) {
            Log::error('Shopify check store failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Disconnect a Shopify store (soft delete - keeps products).
     *
     * POST /api/shopify/disconnect
     * Body: { "store_id": 123 }
     */
    public function disconnect(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                Log::warning('Shopify disconnect: authentication required');

                return $this->error('Authentication required.', 401);
            }

            $storeId = $request->input('store_id');
            Log::debug('Shopify disconnect request', [
                'user_id' => $user->id,
                'store_id_input' => $storeId,
            ]);

            if (! $storeId) {
                Log::warning('Shopify disconnect: missing store_id', ['user_id' => $user->id]);

                return $this->error('Missing store_id parameter.', 400);
            }

            $store = $this->storeRepository->findById((int) $storeId, $user->id);
            if (! $store) {
                Log::warning('Shopify disconnect: store not found', [
                    'user_id' => $user->id,
                    'store_id' => (int) $storeId,
                ]);

                return $this->error('Store not found or not owned by you.', 404);
            }

            $this->storeRepository->softDisconnect($store);

            Log::info('Shopify store disconnected', [
                'store_id' => $store->id,
                'store' => $store->store_domain,
                'user_id' => $user->id,
            ]);

            return $this->success([], 'Shopify store disconnected successfully. Imported products are preserved.');
        } catch (Throwable $e) {
            Log::error('Shopify disconnect failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Reconnect a previously disconnected store.
     *
     * POST /api/shopify/reconnect
     * Body: { "shop": "your-store" }
     */
    public function reconnect(ShopifyConnectRequest $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $storeDomain = $this->shopifyService->normalizeDomain($request->input('shop'));

            // Generate OAuth URL for reconnection
            $state = $this->shopifyService->generateState();
            Session::put('shopify_oauth_state', $state);
            Session::put('shopify_oauth_user_id', $user->id);
            Session::put('shopify_oauth_store', $storeDomain);
            Session::put('shopify_oauth_reconnect', true);

            $authUrl = $this->shopifyService->buildAuthorizationUrl($storeDomain, $state);

            return $this->success([
                'store_domain' => $storeDomain,
                'authorization_url' => $authUrl,
            ], 'Reconnection authorization URL generated.');
        } catch (Throwable $e) {
            Log::error('Shopify reconnect failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Preview products from a Shopify store.
     *
     * GET /api/shopify/stores/{store}/products
     * Query: ?cursor=&limit=20&search=
     */
    public function products(Request $request, ShopifyStore $store): JsonResponse
    {
        try {
            $this->authorize('view', $store);

            $this->graphQL->forStore($store);

            $cursor = $request->input('cursor');
            $limit = min((int) ($request->input('limit', 20)), 250);

            $result = $this->graphQL->fetchProducts($limit, $cursor);

            if (! $result) {
                return $this->error('Failed to fetch products from Shopify.', 502);
            }

            $products = array_map(
                fn (array $node) => (new ShopifyProductPreviewResource($node))->toArray($request),
                $result['products']
            );

            return $this->success([
                'store' => new ShopifyStoreResource($store),
                'products' => $products,
                'total_count' => $this->graphQL->countProducts(),
                'fetched_count' => count($products),
                'has_next_page' => $result['has_next_page'],
                'end_cursor' => $result['end_cursor'],
            ], 'Products retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify products preview failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Import selected products from Shopify.
     *
     * POST /api/shopify/import
     * Body: {
     *   "store_id": 123,
     *   "product_ids": ["gid://shopify/Product/123", "456"],           // optional
     *   "collection_ids": ["gid://shopify/Collection/1", "2"],         // optional
     *   "tags": ["electronics", "sale"],                                // optional
     *   "status_filter": "active",                                       // optional: active, draft, archived, any
     *   "updated_since": "2024-01-01T00:00:00Z",                        // optional: incremental sync
     *   "import_all": true,                                               // optional: import all products
     *   "category_id": 5,
     *   "default_condition": "new",
     *   "download_images": true
     * }
     */
    public function import(ShopifySelectImportRequest $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $storeId = $request->input('store_id');
            $store = $this->storeRepository->findById((int) $storeId, $user->id);
            if (! $store) {
                return $this->error('Store not found or not owned by you.', 404);
            }

            // ─── Selection method ────────────────────────────────────
            $productIds = $request->input('product_ids', []);
            $collectionIds = $request->input('collection_ids');
            $tags = $request->input('tags');
            $statusFilter = $request->input('status_filter');
            $updatedSince = $request->input('updated_since');
            $importAll = $request->boolean('import_all', false);

            // ─── Import options ──────────────────────────────────────
            $categoryId = $request->input('category_id');
            $defaultCondition = $request->input('default_condition', 'new');
            $defaultCurrency = $request->input('default_currency', 'USD');
            $downloadImages = $request->boolean('download_images', true);
            $priceSyncPolicy = $request->input('price_sync_policy');
            $imageSyncStrategy = $request->input('image_sync_strategy');

            // Normalize product IDs (accept both gid:// and numeric)
            $normalizedIds = array_map(function ($id) {
                if (str_contains((string) $id, 'gid://')) {
                    $parts = explode('/', $id);

                    return end($parts);
                }

                return (string) $id;
            }, $productIds);

            // Normalize collection IDs
            $normalizedCollectionIds = null;
            if (! empty($collectionIds)) {
                $normalizedCollectionIds = array_map(function ($id) {
                    if (str_contains((string) $id, 'gid://')) {
                        $parts = explode('/', $id);

                        return end($parts);
                    }

                    return (string) $id;
                }, $collectionIds);
            }

            // Determine total count for the import record
            $totalProducts = count($normalizedIds);
            if (empty($normalizedIds)) {
                // If using filters, set to 0 initially (updated by job)
                $totalProducts = 0;
            }

            // Create import record
            $import = $this->importRepository->create([
                'shopify_store_id' => $store->id,
                'profile_id' => $store->profile_id,
                'user_id' => $user->id,
                'status' => 'pending',
                'total_products' => $totalProducts,
                'imported_count' => 0,
                'selected_product_ids' => $normalizedIds,
                'summary' => [
                    'selection_method' => ! empty($normalizedIds) ? 'product_ids'
                        : ($normalizedCollectionIds ? 'collections'
                            : ($tags ? 'tags'
                                : ($importAll ? 'all' : 'filter'))),
                    'has_collection_filter' => ! empty($normalizedCollectionIds),
                    'has_tag_filter' => ! empty($tags),
                    'has_status_filter' => ! empty($statusFilter),
                    'is_incremental' => ! empty($updatedSince),
                ],
                'started_at' => now(),
            ]);

            // Dispatch async import job with all filter params + policies
            ShopifyProductImportJob::dispatch(
                storeId: $store->id,
                importId: $import->id,
                userId: $user->id,
                productIds: $normalizedIds,
                collectionIds: $normalizedCollectionIds,
                tags: $tags,
                statusFilter: $statusFilter,
                updatedSince: $updatedSince,
                importAll: $importAll,
                categoryId: $categoryId,
                defaultCondition: $defaultCondition,
                defaultCurrency: $defaultCurrency,
                downloadImages: $downloadImages,
                priceSyncPolicy: $priceSyncPolicy,
                imageSyncStrategy: $imageSyncStrategy,
            );

            $message = 'Import started. ';
            if (! empty($normalizedIds)) {
                $message .= 'Processing '.count($normalizedIds).' products in the background.';
            } elseif (! empty($normalizedCollectionIds)) {
                $message .= 'Importing products from '.count($normalizedCollectionIds).' collection(s) in the background.';
            } elseif (! empty($tags)) {
                $message .= 'Importing products matching '.count($tags).' tag(s) in the background.';
            } elseif ($importAll) {
                $message .= 'Importing all products from the store in the background.';
            } else {
                $message .= 'Processing in the background.';
            }

            return $this->success([
                'import' => new ShopifyImportResource($import),
                'message' => $message,
            ], 'Import started successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify import failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get import history for a store.
     *
     * GET /api/shopify/import/history?store_id=123&page=1&per_page=20
     */
    public function importHistory(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $storeId = $request->input('store_id');
            if (! $storeId) {
                return $this->error('Missing store_id parameter.', 400);
            }

            $store = $this->storeRepository->findById((int) $storeId, $user->id);
            if (! $store) {
                return $this->error('Store not found or not owned by you.', 404);
            }

            $perPage = min((int) ($request->input('per_page', 20)), 50);
            $imports = $this->importRepository->getByStore($store->id, $perPage);

            return $this->success([
                'store' => new ShopifyStoreResource($store),
                'imports' => ShopifyImportResource::collection($imports),
                'pagination' => [
                    'current_page' => $imports->currentPage(),
                    'per_page' => $imports->perPage(),
                    'total' => $imports->total(),
                    'last_page' => $imports->lastPage(),
                ],
            ], 'Import history retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify import history failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get a specific import's details with logs.
     *
     * GET /api/shopify/import/{id}?store_id=123
     */
    public function importDetail(Request $request, int $importId): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $storeId = $request->input('store_id');
            if (! $storeId) {
                return $this->error('Missing store_id parameter.', 400);
            }

            $store = $this->storeRepository->findById((int) $storeId, $user->id);
            if (! $store) {
                return $this->error('Store not found or not owned by you.', 404);
            }

            $import = $this->importRepository->findWithLogs($importId, $store->id);
            if (! $import) {
                return $this->error('Import record not found.', 404);
            }

            return $this->success([
                'import' => new ShopifyImportResource($import),
            ], 'Import details retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify import detail failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get dashboard status for all connected stores.
     *
     * GET /api/shopify/status
     */
    public function status(): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            // Try profile-based lookup first, fall back to user_id-based
            $profile = $this->resolveProfile($user->id);
            if ($profile && $profile->id) {
                $statusData = $this->importManager->importStatusForProfile($profile->id);
            } else {
                // Fallback: count stores directly by user_id
                $stores = $this->storeRepository->getByUser($user->id);
                $statusData = [
                    'stores' => ShopifyStoreResource::collection($stores),
                    'total_stores' => $stores->count(),
                    'total_imported' => 0,
                ];
                Log::info('Shopify status: profile not found, falling back to user_id', [
                    'user_id' => $user->id,
                    'stores_found' => $stores->count(),
                ]);
            }

            Log::info('Shopify status retrieved', [
                'user_id' => $user->id,
                'total_stores' => $statusData['total_stores'] ?? 0,
            ]);

            return $this->success($statusData, 'Dashboard status retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify status failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get all locally imported products.
     *
     * GET /api/shopify/products?page=1&per_page=20&store_id=123
     */
    public function importedProducts(Request $request): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $perPage = min((int) ($request->input('per_page', 20)), 50);
            $products = $this->importManager->importedProducts($perPage);

            return $this->success([
                'products' => ShopifyProductPreviewResource::collection($products),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ],
            ], 'Imported products retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify imported products failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Cancel a pending or in-progress import.
     *
     * POST /api/shopify/import/{importId}/cancel
     * Body: { "store_id": 123 }
     *
     * The running job checks the 'cancelled' status before each chunk.
     */
    public function cancel(Request $request, int $importId): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $storeId = $request->input('store_id');
            if (! $storeId) {
                return $this->error('Missing store_id parameter.', 400);
            }

            $store = $this->storeRepository->findById((int) $storeId, $user->id);
            if (! $store) {
                return $this->error('Store not found or not owned by you.', 404);
            }

            $import = $this->importRepository->findForUser($importId, $store->id, $user->id);
            if (! $import) {
                return $this->error('Import record not found.', 404);
            }

            if (! $import->isCancellable()) {
                return $this->error(
                    "Import cannot be cancelled in its current state: {$import->status}.", 400
                );
            }

            $cancelled = $this->importRepository->cancel($import);

            Log::info('Shopify import cancelled', [
                'import_id' => $importId,
                'store_id' => $store->id,
                'user_id' => $user->id,
            ]);

            return $this->success([
                'import' => new ShopifyImportResource($cancelled),
            ], 'Import cancelled successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify import cancel failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Resume an interrupted import.
     *
     * POST /api/shopify/import/{importId}/resume
     * Body: { "store_id": 123 }
     *
     * Creates a new import record that continues from where it left off.
     */
    public function resume(Request $request, int $importId): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $storeId = $request->input('store_id');
            if (! $storeId) {
                return $this->error('Missing store_id parameter.', 400);
            }

            $store = $this->storeRepository->findById((int) $storeId, $user->id);
            if (! $store) {
                return $this->error('Store not found or not owned by you.', 404);
            }

            $import = $this->importRepository->findForUser($importId, $store->id, $user->id);
            if (! $import) {
                return $this->error('Import record not found.', 404);
            }

            if (! $import->isResumable()) {
                return $this->error(
                    'Import cannot be resumed. Only interrupted imports with partial progress can be resumed.', 400
                );
            }

            // Check for active imports already running
            if ($this->importRepository->hasActive($store->id)) {
                return $this->error(
                    'Cannot resume: another import is already active for this store.', 409
                );
            }

            $resumed = $this->importRepository->resume($import, $user->id);
            if (! $resumed) {
                return $this->error('Failed to create resume import.', 500);
            }

            $this->importRepository->dispatchResume($resumed, $store);

            Log::info('Shopify import resumed', [
                'original_import_id' => $importId,
                'resumed_import_id' => $resumed->id,
                'store_id' => $store->id,
            ]);

            return $this->success([
                'import' => new ShopifyImportResource($resumed),
            ], 'Import resumed successfully. Continuing from previous progress.');
        } catch (Throwable $e) {
            Log::error('Shopify import resume failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Retry a failed or partial import.
     *
     * POST /api/shopify/import/{importId}/retry
     * Body: { "store_id": 123 }
     *
     * Creates a fresh import (products already imported will be skipped
     * via composite dedup). Failed products will be re-attempted.
     */
    public function retry(Request $request, int $importId): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $storeId = $request->input('store_id');
            if (! $storeId) {
                return $this->error('Missing store_id parameter.', 400);
            }

            $store = $this->storeRepository->findById((int) $storeId, $user->id);
            if (! $store) {
                return $this->error('Store not found or not owned by you.', 404);
            }

            $import = $this->importRepository->findForUser($importId, $store->id, $user->id);
            if (! $import) {
                return $this->error('Import record not found.', 404);
            }

            if (! $import->isRetryable()) {
                return $this->error(
                    'Import cannot be retried. Only failed or partial imports without an existing retry can be retried.', 400
                );
            }

            // Check for active imports already running
            if ($this->importRepository->hasActive($store->id)) {
                return $this->error(
                    'Cannot retry: another import is already active for this store.', 409
                );
            }

            $retry = $this->importRepository->retry($import, $user->id);
            if (! $retry) {
                return $this->error('Failed to create retry import.', 500);
            }

            Log::info('Shopify import retry created', [
                'original_import_id' => $importId,
                'retry_import_id' => $retry->id,
                'store_id' => $store->id,
            ]);

            return $this->success([
                'import' => new ShopifyImportResource($retry),
            ], 'Retry started. Products already imported will be skipped.');
        } catch (Throwable $e) {
            Log::error('Shopify import retry failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get detailed progress for a specific import.
     *
     * GET /api/shopify/import/{importId}/progress?store_id=123
     *
     * Returns: percentage, estimated remaining, lock status, failed items,
     * cancellable/resumable/retryable flags, and recent logs.
     */
    public function importProgress(Request $request, int $importId): JsonResponse
    {
        try {
            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            $storeId = $request->input('store_id');
            if (! $storeId) {
                return $this->error('Missing store_id parameter.', 400);
            }

            $store = $this->storeRepository->findById((int) $storeId, $user->id);
            if (! $store) {
                return $this->error('Store not found or not owned by you.', 404);
            }

            $import = $this->importRepository->findForUser($importId, $store->id, $user->id);
            if (! $import) {
                return $this->error('Import record not found.', 404);
            }

            $progress = $this->importRepository->getProgress($import);

            return $this->success([
                'progress' => $progress,
            ], 'Import progress retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify import progress failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Update store sync settings (price_policy, image_strategy, inventory_sync).
     *
     * PUT /api/shopify/stores/{store}/settings
     */
    public function updateSettings(Request $request, ShopifyStore $store): JsonResponse
    {
        try {
            $this->authorize('update', $store);

            $validated = $request->validate([
                'price_sync_policy' => ['sometimes', 'string', 'in:shopify_always,shopify_never,shopify_if_empty,manual'],
                'image_sync_strategy' => ['sometimes', 'string', 'in:always,new_only,skip'],
                'inventory_sync_enabled' => ['sometimes', 'boolean'],
            ]);

            $store->update($validated);

            Log::info('Shopify store settings updated', [
                'store_id' => $store->id,
                'settings' => $validated,
            ]);

            // Clear cached health data
            Cache::forget("shopify_store_health_{$store->id}");

            return $this->success([
                'store' => new ShopifyStoreResource($store->fresh()),
            ], 'Store settings updated successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify store settings update failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get health status for a connected store.
     *
     * GET /api/shopify/stores/{store}/health
     *
     * Returns: sync lag, token expiry, webhook failure rate, import status,
     * and an overall health assessment (healthy/degraded/unhealthy).
     */
    public function health(ShopifyStore $store): JsonResponse
    {
        try {
            $this->authorize('view', $store);

            // Cache health data for 5 minutes to reduce DB queries
            $cacheKey = "shopify_store_health_{$store->id}";
            $health = Cache::remember($cacheKey, 300, function () use ($store) {
                return new ShopifyStoreHealthResource($store);
            });

            return $this->success([
                'health' => $health,
            ], 'Store health status retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify store health failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Refresh OAuth token for a store (re-connect via OAuth).
     *
     * POST /api/shopify/stores/{store}/refresh-token
     *
     * Returns an authorization URL for token refresh.
     */
    public function refreshToken(Request $request, ShopifyStore $store): JsonResponse
    {
        try {
            $this->authorize('update', $store);

            $user = Auth::guard('jwt')->user();
            if (! $user) {
                return $this->error('Authentication required.', 401);
            }

            // Generate OAuth URL for token refresh
            $state = $this->shopifyService->generateState();

            Session::put('shopify_oauth_state', $state);
            Session::put('shopify_oauth_user_id', $user->id);
            Session::put('shopify_oauth_store', $store->store_domain);
            Session::put('shopify_oauth_reconnect', true);
            Session::put('shopify_oauth_store_id', $store->id);

            $authUrl = $this->shopifyService->buildAuthorizationUrl($store->store_domain, $state);

            Log::info('Shopify token refresh initiated', [
                'store_id' => $store->id,
                'store_domain' => $store->store_domain,
                'user_id' => $user->id,
            ]);

            return $this->success([
                'store_id' => $store->id,
                'store_domain' => $store->store_domain,
                'authorization_url' => $authUrl,
                'refresh_note' => 'Opening this URL will prompt the merchant to re-authorize the app, refreshing the access token.',
            ], 'Token refresh authorization URL generated.');
        } catch (Throwable $e) {
            Log::error('Shopify token refresh failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get webhook metrics for a store.
     *
     * GET /api/shopify/stores/{store}/webhook-metrics
     *
     * Returns: total webhooks received, processed, failed, dead-lettered,
     * duplicates, and a breakdown by topic (last 24h/7d/all time).
     */
    public function webhookMetrics(Request $request, ShopifyStore $store): JsonResponse
    {
        try {
            $this->authorize('view', $store);

            $periods = [
                '24h' => now()->subDay(),
                '7d' => now()->subDays(7),
            ];

            $metrics = [];
            foreach ($periods as $label => $since) {
                $baseQuery = ShopifyWebhookCall::where('shopify_store_id', $store->id)
                    ->where('created_at', '>=', $since);

                $metrics[$label] = [
                    'total' => (clone $baseQuery)->count(),
                    'processed' => (clone $baseQuery)->where('status', ShopifyWebhookCall::STATUS_PROCESSED)->count(),
                    'failed' => (clone $baseQuery)->whereIn('status', [ShopifyWebhookCall::STATUS_FAILED, ShopifyWebhookCall::STATUS_DEAD_LETTER])->count(),
                    'dead_lettered' => (clone $baseQuery)->where('status', ShopifyWebhookCall::STATUS_DEAD_LETTER)->count(),
                    'duplicates' => (clone $baseQuery)->where('status', ShopifyWebhookCall::STATUS_DUPLICATE)->count(),
                ];

                // Topic breakdown
                $byTopic = (clone $baseQuery)
                    ->selectRaw('topic, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed', [ShopifyWebhookCall::STATUS_FAILED])
                    ->groupBy('topic')
                    ->get()
                    ->keyBy('topic')
                    ->toArray();

                $metrics[$label]['by_topic'] = $byTopic;
            }

            // All-time stats
            $allTime = ShopifyWebhookCall::where('shopify_store_id', $store->id);
            $metrics['all_time'] = [
                'total' => (clone $allTime)->count(),
                'processed' => (clone $allTime)->where('status', ShopifyWebhookCall::STATUS_PROCESSED)->count(),
                'failed' => (clone $allTime)->whereIn('status', [ShopifyWebhookCall::STATUS_FAILED, ShopifyWebhookCall::STATUS_DEAD_LETTER])->count(),
                'dead_lettered' => (clone $allTime)->where('status', ShopifyWebhookCall::STATUS_DEAD_LETTER)->count(),
            ];

            return $this->success([
                'store_id' => $store->id,
                'store_domain' => $store->store_domain,
                'metrics' => $metrics,
            ], 'Webhook metrics retrieved successfully.');
        } catch (Throwable $e) {
            Log::error('Shopify webhook metrics failed: '.$e->getMessage());

            return $this->error($e->getMessage(), 500);
        }
    }

    // ─── Protected Helpers ─────────────────────────────────────────

    protected function fetchShopInfo(string $domain, string $token): array
    {
        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
            ])->timeout(10)->get("https://{$domain}/admin/shop.json");

            if ($response->successful()) {
                return $response->json()['shop'] ?? [];
            }
        } catch (\Exception $e) {
            Log::warning("Could not fetch shop info for {$domain}: ".$e->getMessage());
        }

        return [];
    }

    protected function registerWebhooksForStore(ShopifyStore $store): void
    {
        try {
            $webhookManager = app(ShopifyWebhookManager::class);
            $baseUrl = config('app.url');
            $webhookManager->registerWebhooks($store, $baseUrl);
        } catch (\Exception $e) {
            Log::warning("Failed to register webhooks for store {$store->store_domain}: ".$e->getMessage());
        }
    }

    protected function resolveProfile(int $userId): ?Profile
    {
        $user = User::find($userId);

        return $user?->profile;
    }

    protected function success(mixed $data, string $message = '', int $code = 200): JsonResponse
    {
        $this->data = $data;
        $this->status_message = $message;
        $this->status_code = $code;

        return $this->JsonResponseFormat();
    }

    protected function error(string $message, int $code = 400): JsonResponse
    {
        $this->data = [];
        $this->status_message = $message;
        $this->status_code = $code;

        return $this->JsonResponseFormat();
    }
}
