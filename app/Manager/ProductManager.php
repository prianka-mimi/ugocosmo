<?php

namespace App\Manager;

use App\DTOs\ShippingOptionDTO;
use App\Manager\ImageManager;
use App\Models\AliExpressAccount;
use App\Models\EtsyAccount;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductTag;
use App\Models\Profile;
use App\Models\ShopifyStore;
use App\Services\AliExpressProductClient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductManager
{
    public function index(array $params = [], int $perPage = 20)
    {
        $query = Product::active()
            ->with(['seller.user', 'category', 'tags'])
            ->withAvg('reviews', 'rating')
            ->withCount(['reviews', 'saves', 'orderItems']);

        $categoryId = $params['category_id'] ?? $params['category'] ?? null;
        if (! empty($categoryId)) {
            if (is_numeric($categoryId)) {
                $query->where('category_id', (int) $categoryId);
            } else {
                $query->whereHas('category', function ($q) use ($categoryId) {
                    $q->where('name', 'like', "%{$categoryId}%");
                });
            }
        }

        if (! empty($params['condition'])) {
            $query->where('condition', $params['condition']);
        }

        if (! empty($params['product_type'])) {
            $query->where('product_type', $params['product_type']);
        }

        if (isset($params['min_price']) && $params['min_price'] !== null && $params['min_price'] !== '') {
            $query->where('price', '>=', (float) $params['min_price']);
        }

        if (isset($params['max_price']) && $params['max_price'] !== null && $params['max_price'] !== '') {
            $query->where('price', '<=', (float) $params['max_price']);
        }

        if (isset($params['min_rating']) && $params['min_rating'] !== null && $params['min_rating'] !== '') {
            $minRating = (float) $params['min_rating'];
            $query->whereRaw('(SELECT COALESCE(AVG(rating), 0) FROM product_reviews WHERE product_reviews.product_id = products.id) >= ?', [$minRating]);
        }

        if (! empty($params['tag'])) {
            $query->byTag($params['tag']);
        }

        $wholesale = $params['wholesale_only'] ?? $params['wholesale'] ?? null;
        if (! empty($wholesale) && filter_var($wholesale, FILTER_VALIDATE_BOOLEAN)) {
            $query->wholesale();
        }

        if (! empty($params['source'])) {
            $query->where('source', $params['source']);
        }

        if (isset($params['id']) && $params['id'] !== '') {
            $query->where('id', (int) $params['id']);
        }

        if (isset($params['name']) && $params['name'] !== '') {
            $name = $params['name'];
            $query->where('title', 'like', "%{$name}%");
        }

        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $sortField = 'created_at';
        $sortDir = 'desc';
        if (! empty($params['sort'])) {
            $sort = $params['sort'];
            if (in_array($sort, ['newest', 'latest'])) {
                $sortField = 'created_at';
                $sortDir = 'desc';
            } elseif ($sort === 'oldest') {
                $sortField = 'created_at';
                $sortDir = 'asc';
            } elseif (in_array($sort, ['price_asc', 'price_low_high'])) {
                $sortField = 'price';
                $sortDir = 'asc';
            } elseif (in_array($sort, ['price_desc', 'price_high_low'])) {
                $sortField = 'price';
                $sortDir = 'desc';
            } elseif (in_array($sort, ['highest_rated', 'rating_desc'])) {
                $query->orderByRaw('COALESCE(reviews_avg_rating, 0) DESC');
                $sortField = null;
            } elseif (in_array($sort, ['most_popular', 'popular'])) {
                $query->orderByRaw('(order_items_count * 3 + saves_count * 2 + reviews_count) DESC');
                $sortField = null;
            }
        }

        if ($sortField) {
            $query->orderBy($sortField, $sortDir);
        }

        return $query->paginate($perPage);
    }

    public function mine(array $params = []): array|LengthAwarePaginator
    {
        $user = Auth::guard('jwt')->user() ?? Auth::user();
        if (! $user) {
            throw new \Exception('Unauthenticated.', 401);
        }

        $userId = (int) $user->id;

        // Collect all profile IDs that actually belong to this authenticated user
        $userProfileIds = Profile::where('user_id', $userId)->pluck('id')->all();
        $profileId = (int) ($user->profile?->id ?? 0);
        if ($profileId === 0) {
            $profileId = (int) $this->profileId();
        }
        if ($profileId > 0 && ! in_array($profileId, $userProfileIds, true)) {
            $userProfileIds[] = $profileId;
        }
        $userProfileIds = array_values(array_filter(array_unique($userProfileIds)));

        // Collect connected account IDs owned by this user
        $aliExpressAccountIds = AliExpressAccount::withTrashed()
            ->where(function ($q) use ($userId, $userProfileIds) {
                $q->where('user_id', $userId);
                if (! empty($userProfileIds)) {
                    $q->orWhereIn('profile_id', $userProfileIds);
                }
            })
            ->pluck('id')
            ->all();

        $etsyAccountIds = EtsyAccount::withTrashed()
            ->where(function ($q) use ($userId, $userProfileIds) {
                $q->where('user_id', $userId);
                if (! empty($userProfileIds)) {
                    $q->orWhereIn('profile_id', $userProfileIds);
                }
            })
            ->pluck('id')
            ->all();

        $shopifyStoreIds = ShopifyStore::withTrashed()
            ->where(function ($q) use ($userId, $userProfileIds) {
                $q->where('user_id', $userId);
                if (! empty($userProfileIds)) {
                    $q->orWhereIn('profile_id', $userProfileIds);
                }
            })
            ->pluck('id')
            ->all();

        // Strictly scoped to the authenticated seller (local, AliExpress, CSV, Shopify, Etsy)
        // NOTE: seller_id is a profiles.id foreign key — never compare userId directly to seller_id.
        $query = Product::where(function ($q) use ($userProfileIds, $userId, $aliExpressAccountIds, $etsyAccountIds, $shopifyStoreIds) {
            $q->where(function ($sq) use ($userProfileIds, $userId) {
                if (! empty($userProfileIds)) {
                    $sq->whereIn('seller_id', $userProfileIds);
                } else {
                    $sq->whereRaw('0 = 1');
                }
                $sq->orWhereHas('seller', function ($sellerQ) use ($userId) {
                    $sellerQ->where('user_id', $userId);
                });
            });

            if (! empty($aliExpressAccountIds)) {
                $q->orWhereIn('aliexpress_account_id', $aliExpressAccountIds);
            }

            if (! empty($etsyAccountIds)) {
                $q->orWhereIn('etsy_account_id', $etsyAccountIds);
            }

            if (! empty($shopifyStoreIds)) {
                $q->orWhereIn('shopify_store_id', $shopifyStoreIds);
            }
        })
            ->latestFirst()
            ->with(['seller.user', 'category', 'tags'])
            ->withAvg('reviews', 'rating')
            ->withCount(['reviews', 'saves', 'orderItems']);

        // ID filter
        if (isset($params['id']) && $params['id'] !== '') {
            $query->where('id', (int) $params['id']);
        }

        // Name / Title filter
        if (isset($params['name']) && $params['name'] !== '') {
            $name = $params['name'];
            $query->where('title', 'like', "%{$name}%");
        }

        // Search filter (title, description, brand, sku)
        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        // Status filter: 'active', 'inactive', 'all'.
        if (isset($params['active']) && $params['active'] !== '') {
            $query->where('is_active', filter_var($params['active'], FILTER_VALIDATE_BOOLEAN));
        } elseif (isset($params['inactive']) && $params['inactive'] !== '') {
            $query->where('is_active', ! filter_var($params['inactive'], FILTER_VALIDATE_BOOLEAN));
        } elseif (! empty($params['status'])) {
            $status = strtolower($params['status']);
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        } elseif (isset($params['is_active']) && $params['is_active'] !== '' && $params['is_active'] !== null) {
            $query->where('is_active', filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        // Category filter
        $categoryId = $params['category_id'] ?? $params['category'] ?? null;
        if (! empty($categoryId)) {
            if (is_numeric($categoryId)) {
                $query->where('category_id', (int) $categoryId);
            } else {
                $query->whereHas('category', function ($q) use ($categoryId) {
                    $q->where('name', 'like', "%{$categoryId}%");
                });
            }
        }

        // Source filter: 'aliexpress', 'shopify', 'etsy', 'local', 'csv'
        if (! empty($params['source'])) {
            $query->where('source', $params['source']);
        }

        // Condition filter
        if (! empty($params['condition'])) {
            $query->where('condition', $params['condition']);
        }

        // Price range
        if (isset($params['min_price']) && $params['min_price'] !== null && $params['min_price'] !== '') {
            $query->where('price', '>=', (float) $params['min_price']);
        }
        if (isset($params['max_price']) && $params['max_price'] !== null && $params['max_price'] !== '') {
            $query->where('price', '<=', (float) $params['max_price']);
        }

        // Sorting
        $sortField = 'created_at';
        $sortDir = 'desc';
        if (! empty($params['sort'])) {
            $sort = $params['sort'];
            if (in_array($sort, ['newest', 'latest'])) {
                $sortField = 'created_at';
                $sortDir = 'desc';
            } elseif ($sort === 'oldest') {
                $sortField = 'created_at';
                $sortDir = 'asc';
            } elseif (in_array($sort, ['price_asc', 'price_low_high'])) {
                $sortField = 'price';
                $sortDir = 'asc';
            } elseif (in_array($sort, ['price_desc', 'price_high_low'])) {
                $sortField = 'price';
                $sortDir = 'desc';
            } elseif (in_array($sort, ['highest_rated', 'rating_desc'])) {
                $query->orderByRaw('COALESCE(reviews_avg_rating, 0) DESC');
                $sortField = null;
            } elseif (in_array($sort, ['most_popular', 'popular'])) {
                $query->orderByRaw('(order_items_count * 3 + saves_count * 2 + reviews_count) DESC');
                $sortField = null;
            }
        }

        if ($sortField) {
            $query->orderBy($sortField, $sortDir);
        }

        // Optional pagination if per_page or page is requested, otherwise return array
        if (! empty($params['per_page']) || ! empty($params['page'])) {
            $perPage = ! empty($params['per_page']) ? (int) $params['per_page'] : 20;

            return $query->paginate($perPage);
        }

        return $query->get()
            ->map(fn (Product $product) => $this->format($product))
            ->all();
    }

    public function store(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $this->prepareShippingData($data);
            $profileId = (int) $this->profileId();

            $attributes = [
                'seller_id' => $profileId,

                // 1. Basic Information
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'price' => $data['price'],
                'currency' => $data['currency'] ?? 'USD',
                'compare_at_price' => $data['compare_at_price'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'stock_quantity' => $data['stock_quantity'] ?? null,
                'product_type' => $data['product_type'] ?? null,
                'condition' => $data['condition'] ?? null,
                'product_variants' => ! empty($data['product_variants']) ? $data['product_variants'] : null,

                // 2. Details
                'specifications' => $data['specifications'] ?? null,
                'features_benefits' => $data['features_benefits'] ?? null,
                'weight' => $data['weight'] ?? null,
                'wholesale_product' => filter_var($data['wholesale_product'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'moq' => $data['moq'] ?? null,
                'bulk_pricing' => ! empty($data['bulk_pricing']) ? $data['bulk_pricing'] : null,
                'brand' => $data['brand'] ?? null,
                'sku' => $data['sku'] ?? $this->generateSku($data['title'], $profileId),
                'ean_upc' => $data['ean_upc'] ?? null,

                // 3. Shipping
                'ship_from_address' => $data['ship_from_address'] ?? null,
                'shipping_options' => ! empty($data['shipping_options']) ? $data['shipping_options'] : null,
                'shipping_cost' => $data['shipping_cost'] ?? null,
                'shipping_tiers' => ! empty($data['shipping_tiers']) ? $data['shipping_tiers'] : null,
                'package_dimensions' => ! empty($data['package_dimensions']) ? $data['package_dimensions'] : null,
                'handling_time' => $data['handling_time'] ?? null,
                'estimated_delivery' => $data['estimated_delivery'] ?? null,
                'shipping_info' => $data['shipping_info'] ?? null,
                'location' => $data['local_location'] ?? null,

                // 4. Media & Status
                'is_active' => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'digital_download_url' => $data['digital_download_url'] ?? null,
            ];

            if (Schema::hasColumn('products', 'shipping_strategy')) {
                $attributes['shipping_strategy'] = $data['shipping_strategy'] ?? 'shipengine';
            }

            $product = Product::create($attributes);

            // Handle single image upload
            if (isset($data['image'])) {
                $product->image_url = app(ImageManager::class)->upload($data['image'], 'products');
            }

            // Handle multiple images upload
            if (isset($data['images'])) {
                $paths = [];
                foreach ($data['images'] as $image) {
                    $paths[] = app(ImageManager::class)->upload($image, 'products');
                }
                $product->images = $paths;
            }

            // Handle video upload
            if (isset($data['video'])) {
                $product->video_url = $this->uploadVideo($data['video'], 'products');
            }

            $product->save();

            // Sync tags
            if (! empty($data['tags'])) {
                $this->syncTags($product, $data['tags']);
            }

            return $product->load(['seller.user', 'category', 'tags']);
        });
    }

    public function show(Product $product): Product
    {
        $product->load(['seller.user', 'category', 'tags']);

        // Auto-heal sparse AliExpress products from cached source data
        $this->enrichAliExpressProduct($product);

        $product->frequently_bought_together = $this->getFrequentlyBoughtTogether($product);
        $product->customers_also_bought = $this->getCustomersAlsoBought($product);

        return $product;
    }

    /**
     * Get products frequently bought together with the given product.
     */
    public function getFrequentlyBoughtTogether(Product $product, int $limit = 4)
    {
        // 1. Find product IDs co-purchased in the same orders
        $coBoughtIds = OrderItem::whereIn('order_id', function ($query) use ($product) {
            $query->select('order_id')
                ->from('order_items')
                ->where('product_id', $product->id);
        })
            ->where('product_id', '!=', $product->id)
            ->whereNotNull('product_id')
            ->select('product_id', DB::raw('COUNT(*) as frequency'))
            ->groupBy('product_id')
            ->orderByDesc('frequency')
            ->limit($limit)
            ->pluck('product_id')
            ->toArray();

        $needed = $limit - count($coBoughtIds);

        // 2. Fallback to same category active products if needed
        if ($needed > 0) {
            $excludeIds = array_merge([$product->id], $coBoughtIds);

            $fallbackIds = Product::active()
                ->where('category_id', $product->category_id)
                ->whereNotIn('id', $excludeIds)
                ->latestFirst()
                ->limit($needed)
                ->pluck('id')
                ->toArray();

            $coBoughtIds = array_merge($coBoughtIds, $fallbackIds);
        }

        if (empty($coBoughtIds)) {
            return collect();
        }

        return Product::active()
            ->with(['seller', 'category', 'tags'])
            ->withAvg('reviews', 'rating')
            ->withCount(['reviews', 'saves', 'orderItems'])
            ->whereIn('id', $coBoughtIds)
            ->get()
            ->sortBy(fn ($item) => array_search($item->id, $coBoughtIds))
            ->values();
    }

    /**
     * Get products bought by customers who also bought the given product.
     */
    public function getCustomersAlsoBought(Product $product, int $limit = 6)
    {
        // 1. Find product IDs bought by buyers who purchased this product
        $customerBoughtIds = OrderItem::whereIn('order_id', function ($query) use ($product) {
            $query->select('id')
                ->from('orders')
                ->whereIn('buyer_id', function ($subQuery) use ($product) {
                    $subQuery->select('orders.buyer_id')
                        ->from('orders')
                        ->join('order_items', 'orders.id', '=', 'order_items.order_id')
                        ->where('order_items.product_id', $product->id)
                        ->whereNotNull('orders.buyer_id');
                });
        })
            ->where('product_id', '!=', $product->id)
            ->whereNotNull('product_id')
            ->select('product_id', DB::raw('COUNT(*) as frequency'))
            ->groupBy('product_id')
            ->orderByDesc('frequency')
            ->limit($limit)
            ->pluck('product_id')
            ->toArray();

        $needed = $limit - count($customerBoughtIds);

        // 2. Fallback to active products in same category or latest active products if needed
        if ($needed > 0) {
            $excludeIds = array_merge([$product->id], $customerBoughtIds);

            $fallbackQuery = Product::active()->whereNotIn('id', $excludeIds);
            if ($product->category_id) {
                $fallbackQuery->where('category_id', $product->category_id);
            }

            $fallbackIds = $fallbackQuery->latestFirst()
                ->limit($needed)
                ->pluck('id')
                ->toArray();

            // If still needed and category filter was too narrow, pick any active products
            if (count($fallbackIds) < $needed) {
                $additionalNeeded = $needed - count($fallbackIds);
                $excludeIds = array_merge($excludeIds, $fallbackIds);
                $moreFallbackIds = Product::active()
                    ->whereNotIn('id', $excludeIds)
                    ->latestFirst()
                    ->limit($additionalNeeded)
                    ->pluck('id')
                    ->toArray();
                $fallbackIds = array_merge($fallbackIds, $moreFallbackIds);
            }

            $customerBoughtIds = array_merge($customerBoughtIds, $fallbackIds);
        }

        if (empty($customerBoughtIds)) {
            return collect();
        }

        return Product::active()
            ->with(['seller', 'category', 'tags'])
            ->withAvg('reviews', 'rating')
            ->withCount(['reviews', 'saves', 'orderItems'])
            ->whereIn('id', $customerBoughtIds)
            ->get()
            ->sortBy(fn ($item) => array_search($item->id, $customerBoughtIds))
            ->values();
    }

    public function update(Product $product, array $data): Product
    {
        $this->ensureOwned($product);

        return DB::transaction(function () use ($product, $data) {
            $this->prepareShippingData($data);

            $fillable = [
                // 1. Basic Information
                'title', 'description', 'price', 'currency', 'compare_at_price',
                'category_id', 'stock_quantity', 'product_type', 'condition',
                'product_variants',

                // 2. Details
                'specifications', 'features_benefits', 'weight',
                'wholesale_product', 'moq', 'bulk_pricing', 'brand', 'sku', 'ean_upc',

                // 3. Shipping
                'ship_from_address', 'shipping_options', 'shipping_cost', 'shipping_tiers',
                'package_dimensions', 'handling_time', 'estimated_delivery',
                'shipping_info', 'shipping_strategy', 'location',

                // 4. Media & Status
                'is_active', 'digital_download_url',
            ];

            foreach ($fillable as $field) {
                if ($field === 'shipping_strategy' && ! Schema::hasColumn('products', 'shipping_strategy')) {
                    continue;
                }
                if (array_key_exists($field, $data)) {
                    if (in_array($field, ['is_active', 'wholesale_product'])) {
                        $product->$field = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
                    } else {
                        $product->$field = $data[$field];
                    }
                }
            }

            // Handle single image: remove or upload
            if (! empty($data['remove_image'])) {
                app(ImageManager::class)->delete($product->image_url);
                $product->image_url = null;
            } elseif (isset($data['image'])) {
                app(ImageManager::class)->delete($product->image_url);
                $product->image_url = app(ImageManager::class)->upload($data['image'], 'products');
            }

            // Handle multiple images (always replaces entire array)
            if (isset($data['images'])) {
                foreach (($product->images ?? []) as $oldImage) {
                    app(ImageManager::class)->delete($oldImage);
                }
                $paths = [];
                foreach ($data['images'] as $image) {
                    $paths[] = app(ImageManager::class)->upload($image, 'products');
                }
                $product->images = $paths;
            }

            // Handle video: remove or upload
            if (! empty($data['remove_video'])) {
                $this->deleteVideo($product->video_url);
                $product->video_url = null;
            } elseif (isset($data['video'])) {
                $this->deleteVideo($product->video_url);
                $product->video_url = $this->uploadVideo($data['video'], 'products');
            }

            $product->save();

            // Imported Etsy/AliExpress listings: an explicit seller price/title
            // edit marks the listing seller-managed so the scheduled sync never
            // overwrites it (seller_priced contract of the import services).
            if (in_array($product->source, ['etsy', 'aliexpress'], true)
                && (isset($data['price']) || isset($data['title']))) {
                $this->markSellerManaged($product);
            }

            // Sync tags
            if (array_key_exists('tags', $data)) {
                $this->syncTags($product, $data['tags'] ?? []);
            }

            return $product->load(['seller.user', 'category', 'tags']);
        });
    }

    public function destroy(Product $product): void
    {
        $this->ensureOwned($product);

        DB::transaction(function () use ($product) {
            // Clean up images
            app(ImageManager::class)->delete($product->image_url);

            foreach (($product->images ?? []) as $image) {
                app(ImageManager::class)->delete($image);
            }

            // Clean up video
            $this->deleteVideo($product->video_url);

            $product->delete();
        });
    }

    /**
     * Flag an imported listing as seller-managed after an explicit price
     * or title edit. saveQuietly() avoids re-triggering the ProductObserver
     * for a bookkeeping-only write.
     */
    protected function markSellerManaged(Product $product): void
    {
        $column = $product->source === 'etsy' ? 'etsy_source_data' : 'aliexpress_source_data';
        $source = $product->{$column} ?? [];

        if (($source['seller_priced'] ?? false) === true) {
            return;
        }

        $source['seller_priced'] = true;
        $product->{$column} = $source;
        $product->saveQuietly();
    }

    // ─── Tag Helpers ────────────────────────────────────────────────

    /**
     * Sync tags for a product.
     * Creates new tags if they don't exist, then syncs the relationship.
     *
     * Made public so it can be reused by ShopifyProductImportManager.
     */
    public function syncTags(Product $product, array $tagNames): void
    {
        $tagIds = [];
        foreach (array_unique($tagNames) as $name) {
            $tag = ProductTag::firstOrCreate(['name' => trim($name)]);
            $tagIds[] = $tag->id;
        }
        $product->tags()->sync($tagIds);
    }

    // ─── Video Upload Helpers ───────────────────────────────────────

    /**
     * Upload a video file to storage.
     */
    protected function uploadVideo($file, string $folder): ?string
    {
        if (! $file) {
            return null;
        }

        $extension = $file->getClientOriginalExtension();
        $filename = Str::random(10).'_'.time().'.'.$extension;
        $path = "uploads/{$folder}/videos/{$filename}";

        Storage::disk('public')->makeDirectory("uploads/{$folder}/videos");
        Storage::disk('public')->put($path, file_get_contents($file));

        return $path;
    }

    /**
     * Delete a video file from storage.
     */
    protected function deleteVideo(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    // ─── Formatting & Helpers ───────────────────────────────────────

    /**
     * Silently heal sparse AliExpress products by populating null database
     * fields from the cached aliexpress_source_data JSON column.
     * Only writes to the database when at least one field is actually changed.
     */
    protected function enrichAliExpressProduct(Product $product): void
    {
        if ($product->source !== 'aliexpress') {
            return;
        }

        $src = is_array($product->aliexpress_source_data) ? $product->aliexpress_source_data : [];

        if (empty($src)) {
            return;
        }

        $updates = [];

        if ($product->compare_at_price === null) {
            $raw = $src['original_price'] ?? $src['original_price_min'] ?? $src['compare_at_price'] ?? null;
            if ($raw !== null) {
                $updates['compare_at_price'] = (float) $raw;
            }
        }

        if (empty($product->brand)) {
            $brand = $src['brand'] ?? null;
            if ($brand) {
                $updates['brand'] = (string) $brand;
            }
        }

        if (empty($product->sku)) {
            $sku = $src['skus'][0]['sku_code'] ?? $src['sku'] ?? null;
            if ($sku) {
                $updates['sku'] = (string) $sku;
            }
        }

        if (empty($product->specifications) && ! empty($src['attributes']) && is_array($src['attributes'])) {
            $specs = implode("\n", array_filter(
                array_map(fn ($a) => isset($a['name'], $a['value']) ? "{$a['name']}: {$a['value']}" : null, $src['attributes'])
            ));
            if ($specs) {
                $updates['specifications'] = $specs;
            }
        }

        if (empty($product->weight)) {
            $weight = $src['package']['weight'] ?? $src['weight'] ?? null;
            if ($weight !== null) {
                $updates['weight'] = (string) $weight;
            }
        }

        if (empty($product->package_dimensions)) {
            $pkg = $src['package'] ?? null;
            if (is_array($pkg) && ! empty($pkg)) {
                $updates['package_dimensions'] = $pkg;
            }
        }

        if ((float) ($product->shipping_cost ?? 0) === 0.0) {
            $cost = $src['shipping_cost'] ?? $src['shipping_fee_amount'] ?? null;
            if ($cost !== null) {
                $updates['shipping_cost'] = (float) $cost;
            }
        }

        if (empty($product->shipping_info)) {
            $carrier = $src['carrier'] ?? $src['shipping']['carrier'] ?? null;
            if ($carrier) {
                $updates['shipping_info'] = (string) $carrier;
            }
        }

        if (empty($product->estimated_delivery)) {
            $eta = $src['shipping']['delivery_time'] ?? $src['shipping']['estimated_delivery'] ?? $src['estimated_delivery'] ?? null;
            if ($eta) {
                $updates['estimated_delivery'] = (string) $eta;
            }
        }

        if ((int) ($product->stock_quantity ?? 0) === 0) {
            $stock = $src['stock'] ?? null;
            if ($stock === null && ! empty($src['skus']) && is_array($src['skus'])) {
                $stock = array_sum(array_column($src['skus'], 'stock'));
            }
            if ((int) $stock > 0) {
                $updates['stock_quantity'] = (int) $stock;
            }
        }

        if (empty($product->product_type)) {
            $updates['product_type'] = 'physical';
        }

        if (empty($product->condition)) {
            $updates['condition'] = 'new';
        }

        if (empty($src['rating']) && ! empty($product->aliexpress_product_id)) {
            try {
                $eval = app(AliExpressProductClient::class)->productEvaluations($product->aliexpress_product_id);
                if (! empty($eval['rating']) && (float) $eval['rating'] > 0) {
                    $src['rating'] = (float) $eval['rating'];
                    $src['reviews_count'] = (int) ($eval['total_reviews'] ?? $eval['ratings_count'] ?? 0);
                    $src['reviews'] = $src['reviews_count'];
                    $updates['aliexpress_source_data'] = $src;
                }
            } catch (\Throwable $e) {
            }
        }

        if (! empty($updates)) {
            $product->fill($updates);
            $product->saveQuietly();
        }
    }

    protected function format(Product $product): array
    {
        $src = ($product->source === 'aliexpress' && is_array($product->aliexpress_source_data))
            ? $product->aliexpress_source_data
            : [];

        // AliExpress rating fallback: prefer database rating → source_data rating → 0.0
        $rating = isset($product->reviews_avg_rating) && $product->reviews_avg_rating !== null
            ? round((float) $product->reviews_avg_rating, 1)
            : (isset($src['rating']) ? (float) $src['rating'] : 0.0);

        // AliExpress review count fallback: prefer database count → source_data count → 0
        $ratingCount = isset($product->reviews_count) && $product->reviews_count > 0
            ? (int) $product->reviews_count
            : (int) ($src['reviews_count'] ?? $src['reviews'] ?? 0);

        return [
            'id' => $product->id,
            'seller' => [
                'id' => $product->seller?->id,
                'full_name' => $product->seller?->full_name,
                'username' => $product->seller?->username,
                'avatar_url' => ImageManager::fileUrl($product->seller?->avatar_url),
                'is_verified' => (bool) $product->seller?->user?->is_verified,
            ],
            'title' => $product->title,
            'description' => $product->clean_description,
            'price' => $product->price,
            'currency' => $product->currency ?? 'USD',
            'compare_at_price' => $product->compare_at_price
                ?? ($src['original_price'] ?? ($src['original_price_min'] ?? null)),
            'category' => $product->relationLoaded('category') && $product->category
                ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                ]
                : null,
            'category_id' => $product->category_id,
            'stock_quantity' => $product->stock_quantity,
            'product_type' => strtolower((string) ($product->product_type ?? '')) === 'digital' ? 'digital' : 'physical',
            'condition' => in_array(strtolower((string) ($product->condition ?? '')), ['new', 'used', 'refurbished'], true) ? strtolower((string) $product->condition) : 'new',
            'product_variants' => $product->product_variants ?? [],
            'specifications' => $product->specifications
                ?? (! empty($src['attributes']) && is_array($src['attributes'])
                    ? implode("\n", array_filter(array_map(
                        fn ($a) => isset($a['name'], $a['value']) ? "{$a['name']}: {$a['value']}" : null,
                        $src['attributes']
                    )))
                    : null),
            'features_benefits' => $product->features_benefits,
            'weight' => $product->weight ?? ($src['package']['weight'] ?? ($src['weight'] ?? null)),
            'wholesale_product' => (bool) $product->wholesale_product,
            'moq' => $product->moq,
            'bulk_pricing' => $product->bulk_pricing ?? [],
            'brand' => $product->brand ?? ($src['brand'] ?? null),
            'sku' => $product->sku ?? ($src['skus'][0]['sku_code'] ?? ($src['sku'] ?? null)),
            'ean_upc' => $product->ean_upc,
            'tags' => $product->relationLoaded('tags')
                ? $product->tags->pluck('name')
                : [],
            'image' => $product->main_image_url ?? ImageManager::fileUrl($product->image_url),
            'images' => $product->all_image_urls ?? ($product->images
                ? array_map(fn ($img) => ImageManager::fileUrl($img), $product->images)
                : []),
            'video' => ImageManager::fileUrl($product->video_url),
            'image_url' => ImageManager::fileUrl($product->image_url),
            'product_images' => $product->images
                ? array_map(fn ($img) => ImageManager::fileUrl($img), $product->images)
                : [],
            'video_url' => ImageManager::fileUrl($product->video_url),
            'ship_from_address' => $product->ship_from_address,
            'shipping_options' => $product->shipping_options ?? [],
            'shipping_cost' => $product->shipping_cost
                ?? (isset($src['shipping_cost']) ? (float) $src['shipping_cost'] : null),
            'shipping_tiers' => $product->shipping_tiers,
            'package_dimensions' => $product->package_dimensions ?? ($src['package'] ?? null),
            'handling_time' => $product->handling_time,
            'estimated_delivery' => $product->estimated_delivery
                ?? ($src['shipping']['delivery_time'] ?? ($src['shipping']['estimated_delivery']
                    ?? ($product->source === 'aliexpress' ? '7-15 business days' : null))),
            'shipping_info' => $product->shipping_info
                ?? ($src['carrier'] ?? ($product->source === 'aliexpress' ? 'AliExpress Standard Shipping' : null)),
            'carrier' => $product->shipping_info
                ?? ($src['carrier'] ?? ($product->source === 'aliexpress' ? 'AliExpress Standard Shipping' : null)),
            'shipping_fee' => $src['shipping_fee']
                ?? ((float) ($product->shipping_cost ?? 0) > 0 ? '$'.number_format((float) $product->shipping_cost, 2) : 'Free Shipping'),
            'is_free_shipping' => isset($src['is_free_shipping'])
                ? (bool) $src['is_free_shipping']
                : ((float) ($product->shipping_cost ?? 0) <= 0),
            'location' => $product->location,
            'digital_download_url' => $product->digital_download_url,
            'is_active' => (bool) $product->is_active,
            'status' => $product->is_active ? 'active' : 'inactive',
            'source' => $product->source ?? 'local',
            'aliexpress_product_id' => $product->aliexpress_product_id,
            'shopify_product_id' => $product->shopify_product_id,
            'etsy_listing_id' => $product->etsy_listing_id,
            'orders' => (int) ($src['orders'] ?? 0),
            'sold' => $src['sold'] ?? ($src['sold_desc'] ?? null),
            'rating' => $rating,
            'rating_count' => $ratingCount,
            'skus' => $src['skus'] ?? ($product->product_variants ?? []),
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    protected function prepareShippingData(array &$data): void
    {
        // 1. Support both allowed_shipping_options and shipping_options from payload
        $rawOptions = $data['allowed_shipping_options'] ?? $data['shipping_options'] ?? null;

        // Ensure rawOptions is an array so enable-flag-only requests are handled
        if (! is_array($rawOptions)) {
            $rawOptions = [];
        }

        if (is_array($rawOptions)) {
            $normalized = [];
            foreach ($rawOptions as $key => $opt) {
                if (is_int($key) && is_string($opt)) {
                    $canon = ShippingOptionDTO::normalizeKey($opt);
                    if ($canon) {
                        $normalized[] = $canon;
                    }
                } elseif (is_string($key) && $opt) {
                    $canon = ShippingOptionDTO::normalizeKey($key);
                    if ($canon) {
                        $normalized[] = $canon;
                    }
                }
            }

            // Remove free_shipping / local_shipping unless explicitly enabled
            $normalized = array_filter($normalized, function ($canon) use ($data) {
                if ($canon === ShippingOptionDTO::FREE_SHIPPING) {
                    return (bool) filter_var($data['free_shipping_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                }
                if ($canon === ShippingOptionDTO::LOCAL_SHIPPING) {
                    return (bool) filter_var($data['local_shipping_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                }
                return true;
            });

            // Add free_shipping / local_shipping if their enable flags are true but not already in the list
            if ((bool) filter_var($data['free_shipping_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $normalized[] = ShippingOptionDTO::FREE_SHIPPING;
            }
            if ((bool) filter_var($data['local_shipping_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $normalized[] = ShippingOptionDTO::LOCAL_SHIPPING;
            }

            $data['shipping_options'] = ! empty($normalized) ? array_values(array_unique($normalized)) : [ShippingOptionDTO::STANDARD_SHIPPING];
        }

        // Synchronize AliExpress specific fields
        if (isset($data['aliexpress_shipping_cost']) && is_numeric($data['aliexpress_shipping_cost'])) {
            $data['shipping_cost'] = round((float) $data['aliexpress_shipping_cost'], 2);
        }

        if (isset($data['estimated_delivery_time']) && ! empty($data['estimated_delivery_time'])) {
            $data['estimated_delivery'] = (string) $data['estimated_delivery_time'];
        }

        // 2. Compile shipping_tiers from explicit fields or shipping_tiers array
        $tiers = is_array($data['shipping_tiers'] ?? null) ? $data['shipping_tiers'] : [];

        if (isset($data['standard_shipping_cost']) && is_numeric($data['standard_shipping_cost'])) {
            $tiers['standard_shipping_cost'] = round((float) $data['standard_shipping_cost'], 2);
        }
        if (isset($data['express_shipping_cost']) && is_numeric($data['express_shipping_cost'])) {
            $tiers['express_shipping_cost'] = round((float) $data['express_shipping_cost'], 2);
        }
        if (isset($data['international_shipping_cost']) && is_numeric($data['international_shipping_cost'])) {
            $tiers['international_shipping_cost'] = round((float) $data['international_shipping_cost'], 2);
        }

        // If standard_shipping_cost not set in tiers but shipping_cost exists, mirror it
        if (! isset($tiers['standard_shipping_cost']) && isset($data['shipping_cost']) && is_numeric($data['shipping_cost'])) {
            $tiers['standard_shipping_cost'] = round((float) $data['shipping_cost'], 2);
        }

        // Only include free shipping / local pickup if explicitly allowed by seller
        $enabledKeys = $data['shipping_options'] ?? [];
        if (in_array(ShippingOptionDTO::FREE_SHIPPING, $enabledKeys, true)) {
            $tiers['free_shipping_enabled'] = true;
            $tiers['free_shipping_cost'] = 0.00;
            $tiers['free_shipping_estimated_delivery'] = $data['free_shipping_estimated_delivery'] ?? $data['shipping_tiers']['free_shipping_estimated_delivery'] ?? '5-7 business days';
        }
        if (in_array(ShippingOptionDTO::LOCAL_SHIPPING, $enabledKeys, true) || in_array(ShippingOptionDTO::LOCAL_PICKUP, $enabledKeys, true)) {
            $tiers['local_shipping_enabled'] = true;
            $tiers['local_shipping_cost'] = 0.00;
            $tiers['local_shipping_estimated_delivery'] = $data['local_shipping_estimated_delivery'] ?? ($data['shipping_tiers']['local_shipping_estimated_delivery'] ?? '1-2 business days');
            $tiers['local_pickup_cost'] = 0.00;
        }
        if (in_array(ShippingOptionDTO::MULTI_ITEM_PICKUP, $enabledKeys, true)) {
            $tiers['multi_item_pickup_cost'] = isset($data['multi_item_pickup_cost']) && is_numeric($data['multi_item_pickup_cost'])
                ? round((float) $data['multi_item_pickup_cost'], 2)
                : (isset($tiers['multi_item_pickup_cost']) && is_numeric($tiers['multi_item_pickup_cost'])
                    ? round((float) $tiers['multi_item_pickup_cost'], 2)
                    : (isset($data['shipping_cost']) && is_numeric($data['shipping_cost']) ? round((float) $data['shipping_cost'], 2) : 7.99));
        }
        if (in_array(ShippingOptionDTO::HEAVY_ITEM_PICKUP, $enabledKeys, true)) {
            $tiers['heavy_item_pickup_cost'] = isset($data['heavy_item_pickup_cost']) && is_numeric($data['heavy_item_pickup_cost'])
                ? round((float) $data['heavy_item_pickup_cost'], 2)
                : (isset($tiers['heavy_item_pickup_cost']) && is_numeric($tiers['heavy_item_pickup_cost'])
                    ? round((float) $tiers['heavy_item_pickup_cost'], 2)
                    : (isset($data['shipping_cost']) && is_numeric($data['shipping_cost']) ? round((float) $data['shipping_cost'], 2) : 14.99));
        }

        foreach ($enabledKeys as $optKey) {
            if (is_string($optKey) && str_ends_with($optKey, '_pickup') && ! isset($tiers[$optKey.'_cost'])) {
                $tiers[$optKey.'_cost'] = 0.00;
            }
        }

        $data['shipping_tiers'] = ! empty($tiers) ? $tiers : null;
    }

    protected function ensureOwned(Product $product): void
    {
        $user = Auth::guard('jwt')->user() ?? Auth::user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->role === 'admin' || (method_exists($user, 'hasRole') && $user->hasRole('admin'))) {
            return;
        }

        $profileId = (int) $this->profileId();
        $candidates = array_filter(array_unique([
            (int) $user->id,
            $profileId,
            (int) ($user->profile?->id ?? 0),
        ]));

        if (in_array((int) $product->seller_id, $candidates, true)) {
            return;
        }

        // Check if the product's seller profile belongs to the user
        if ($product->seller && (int) $product->seller->user_id === (int) $user->id) {
            return;
        }

        // Check if the linked external integration account belongs to the user
        if ($product->aliexpress_account_id && $product->aliexpressAccount) {
            if ((int) $product->aliexpressAccount->user_id === (int) $user->id ||
                in_array((int) $product->aliexpressAccount->profile_id, $candidates, true)) {
                return;
            }
        }

        if ($product->etsy_account_id && $product->etsyAccount) {
            if ((int) $product->etsyAccount->user_id === (int) $user->id ||
                in_array((int) $product->etsyAccount->profile_id, $candidates, true)) {
                return;
            }
        }

        abort(403, 'You do not have permission to modify this product.');
    }

    /**
     * Toggle a product's active status between inactive and active.
     * Ownership is enforced via ensureOwned() — non-owners receive 403.
     */
    public function toggleStatus(Product $product, ?bool $targetActive = null): Product
    {
        $this->ensureOwned($product);

        $product->is_active = $targetActive !== null ? $targetActive : ! $product->is_active;
        $product->save();

        return $product->load(['seller.user', 'category', 'tags']);
    }

    protected function generateSku(string $title, int $profileId): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $title), 0, 4));
        $prefix = str_pad($prefix ?: 'PRD', 4, 'X');
        $count  = Product::where('seller_id', $profileId)->count() + 1;

        return $prefix.'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }

    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user() ?? Auth::user();

        if (! $user) {
            throw new \Exception('Unauthenticated.', 401);
        }

        $profile = $user->profile;

        if (! $profile) {
            $profile = Profile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'full_name' => $user->name,
                    'username' => $user->username ?? 'user_'.$user->id,
                    'email' => $user->email,
                    'is_seller' => true,
                ]
            );
        }

        return (int) $profile->id;
    }
}
