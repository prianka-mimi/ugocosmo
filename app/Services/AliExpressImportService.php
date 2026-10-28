<?php

namespace App\Services;

use App\DTOs\AliExpress\AliExpressImportOptions;
use App\DTOs\AliExpress\AliExpressProductDTO;
use App\Exceptions\AliExpress\TokenExpiredException;
use App\Jobs\AliExpressImageDownloadJob;
use App\Manager\ImageManager;
use App\Models\AliExpressAccount;
use App\Models\AliExpressImport;
use App\Models\AliExpressImportLog;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Converts explicitly selected AliExpress products into marketplace
 * listings. Imported products are NOT inventory: stock always starts at
 * zero and only rises when the seller receives physical goods.
 */
class AliExpressImportService
{
    public function __construct(
        protected AliExpressProductService $products,
        protected ImageManager $imageManager,
    ) {}

    /**
     * Import (create or update) a single selected product.
     *
     * @return array{imported: bool, updated: bool, skipped: bool, failed: bool, error: string|null, product: Product|null}
     */
    public function importProduct(
        AliExpressAccount $account,
        AliExpressImport $import,
        string $productId,
        AliExpressImportOptions $options,
    ): array {
        $result = [
            'imported' => false,
            'updated' => false,
            'skipped' => false,
            'failed' => false,
            'error' => null,
            'product' => null,
        ];

        try {
            $dto = $this->products->getProduct($account, $productId);

            $product = $this->findImported($account, $productId);

            if ($product && $this->isUnchanged($product, $dto, $options)) {
                $this->log($import, $account, $dto, AliExpressImportLog::ACTION_SKIPPED, 'Unchanged since last import');
                $result['skipped'] = true;
                $result['product'] = $product;

                return $result;
            }

            $attributes = $this->buildAttributes($account, $dto, $options, $product !== null);

            if ($product) {
                $product->update($attributes);
                $this->log($import, $account, $dto, AliExpressImportLog::ACTION_UPDATED, 'Updated via import (existing listing)');
                $result['updated'] = true;
            } else {
                try {
                    $product = Product::create($attributes);
                } catch (UniqueConstraintViolationException $e) {
                    // Concurrent import of the same product for this account:
                    // the unique index (source, aliexpress_product_id,
                    // aliexpress_account_id) rejected the insert. Treat it as
                    // an idempotent re-import (update) instead of a failure.
                    $product = $this->findImported($account, $productId);

                    if (! $product) {
                        throw $e;
                    }

                    $product->update($this->buildAttributes($account, $dto, $options, true));
                }

                $this->log($import, $account, $dto, AliExpressImportLog::ACTION_IMPORTED);
                $result['imported'] = true;
            }

            $result['product'] = $product;

            if ($options->downloadImages) {
                $this->dispatchImageDownload($account, $product, $dto);
            }

            return $result;
        } catch (TokenExpiredException $e) {
            Log::error("AliExpressImportService: token expired during import {$productId}: ".$e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $result['failed'] = true;
            $result['error'] = $e->getMessage();

            $this->log($import, $account, null, AliExpressImportLog::ACTION_FAILED, null, $productId, $e->getMessage());

            Log::error("AliExpressImportService: failed to import product {$productId}: ".$e->getMessage());

            return $result;
        }
    }

    /**
     * Seller edits an imported listing (price, margin, visibility, media...).
     *
     * @param  array{title?: string, description?: string, selling_price?: float, profit_margin?: float, is_active?: bool, category_id?: int|null, images?: array, product_variants?: array, featured_image?: UploadedFile|null}  $data
     */
    public function updateImportedProduct(Product $product, array $data): Product
    {
        $source = $product->aliexpress_source_data ?? [];
        $cost = (float) ($source['cost_price'] ?? 0);

        $update = [];

        if (isset($data['title'])) {
            $update['title'] = $data['title'];
        }
        if (isset($data['description'])) {
            $update['description'] = $data['description'];
        }
        if (array_key_exists('category_id', $data)) {
            $update['category_id'] = $data['category_id'];
        }
        if (array_key_exists('is_active', $data)) {
            $update['is_active'] = (bool) $data['is_active'];
        }
        if (isset($data['images'])) {
            $update['images'] = $data['images'];
        }
        if (isset($data['product_variants'])) {
            $update['product_variants'] = $data['product_variants'];
        }

        // Price: explicit selling price wins; otherwise recompute from margin.
        if (isset($data['selling_price'])) {
            $update['price'] = round((float) $data['selling_price'], 2);
            // Seller-managed price: the automated stock sync must never reprice.
            $source['seller_priced'] = true;
        } elseif (array_key_exists('profit_margin', $data) && $cost > 0) {
            $update['price'] = round($cost * (1 + (float) $data['profit_margin'] / 100), 2);
            $source['profit_margin'] = (float) $data['profit_margin'];
            $source['seller_priced'] = false;
        }

        if (isset($data['featured_image']) && $data['featured_image'] instanceof UploadedFile) {
            $path = $this->imageManager->upload($data['featured_image'], 'products');
            if ($path) {
                $update['image_url'] = $path;
            }
        }

        if ($source !== []) {
            $update['aliexpress_source_data'] = $source;
        }

        $product->update($update);

        return $product->fresh();
    }

    // ─── Protected helpers ─────────────────────────────────────────

    /**
     * The imported listing for this account/product, if any.
     */
    protected function findImported(AliExpressAccount $account, string $productId): ?Product
    {
        return Product::query()
            ->where('source', 'aliexpress')
            ->where('aliexpress_product_id', $productId)
            ->where('aliexpress_account_id', $account->id)
            ->first();
    }

    protected function isUnchanged(Product $product, AliExpressProductDTO $dto, AliExpressImportOptions $options): bool
    {
        $source = $product->aliexpress_source_data ?? [];
        $cost = (float) ($source['cost_price'] ?? 0);
        $margin = (float) ($source['profit_margin'] ?? 0);
        $expectedPrice = round($cost * (1 + $margin / 100), 2);

        if ((float) $product->price !== $expectedPrice) {
            return false;
        }

        if ((bool) $product->is_active !== (bool) $options->isActive) {
            return false;
        }

        if ((int) ($product->category_id ?? 0) !== (int) ($options->categoryId ?? 0)) {
            return false;
        }

        if ($product->title !== $dto->subject) {
            return false;
        }

        return true;
    }

    /**
     * Attributes for creating OR refreshing an imported listing.
     *
     * `$isExisting` must be true when updating a listing the seller already
     * imported: AliExpress data is supplier preview data and must NEVER
     * overwrite Castilo inventory — received stock (stock_quantity) and
     * open reservations (reserved_quantity) are owned exclusively by
     * InventoryService.
     */
    protected function buildAttributes(AliExpressAccount $account, AliExpressProductDTO $dto, AliExpressImportOptions $options, bool $isExisting = false): array
    {
        $cost = $dto->costPrice();
        $margin = $options->profitMargin ?? 0;
        $price = $margin > 0 ? round($cost * (1 + $margin / 100), 2) : $cost;

        $variants = array_map(fn ($sku) => $sku->toLocalVariant(), $dto->skus);

        $categoryId = $options->categoryId;
        if (! $categoryId && ! empty($dto->categoryName)) {
            $cat = ProductCategory::firstOrCreate(
                ['name' => trim($dto->categoryName)],
                ['slug' => Str::slug($dto->categoryName), 'product_type' => 'physical', 'is_active' => true]
            );
            $categoryId = $cat->id;
        }

        $attributes = [
            'seller_id' => $account->profile_id,
            'title' => $dto->subject,
            'description' => $dto->description ? trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($dto->description, ENT_QUOTES | ENT_HTML5, 'UTF-8')))) ?: null : null,
            'price' => $price,
            'currency' => $dto->currency ?? config('services.aliexpress.default_currency', 'USD'),
            'category_id' => $categoryId,
            'product_type' => 'physical',
            'condition' => 'new',
            'brand' => $dto->brand,
            'sku' => $dto->skus[0]->skuCode ?? null,
            'product_variants' => $variants !== [] ? $variants : null,
            'is_active' => false,
            'image_url' => $dto->mainImage,
            'images' => $dto->images ?: null,
            'source' => 'aliexpress',
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            'aliexpress_product_id' => $dto->productId,
            'aliexpress_account_id' => $account->id,
            'aliexpress_product_url' => $dto->productUrl,
            'aliexpress_updated_at' => $dto->gmtModified ? new \DateTimeImmutable($dto->gmtModified) : null,
            'aliexpress_source_data' => [
                'cost_price' => $cost,
                'profit_margin' => $margin,
                'product_id' => $dto->productId,
                'subject' => $dto->subject,
                'price_min' => $dto->priceMin,
                'price_max' => $dto->priceMax,
                'orders' => $dto->orders,
                'rating' => $dto->rating,
                'category_id' => $dto->categoryId,
                'category_name' => $dto->categoryName,
                'attributes' => $dto->attributes,
                'skus' => array_map(fn ($sku) => $sku->toLocalVariant(), $dto->skus),
                'product_url' => $dto->productUrl,
                'images' => $dto->images,
                'imported_at' => now()->toIso8601String(),
            ],
        ];

        if (! $isExisting) {
            $attributes['stock_quantity'] = 0;
            $attributes['reserved_quantity'] = 0;
        }

        return $attributes;
    }

    protected function dispatchImageDownload(AliExpressAccount $account, Product $product, AliExpressProductDTO $dto): void
    {
        if ($dto->images === []) {
            return;
        }

        AliExpressImageDownloadJob::dispatch(
            accountId: $account->id,
            productId: $product->id,
            urls: $dto->images,
            mainUrl: $dto->mainImage,
        );
    }

    protected function log(
        AliExpressImport $import,
        AliExpressAccount $account,
        ?AliExpressProductDTO $dto,
        string $action,
        ?string $reason = null,
        ?string $productIdOverride = null,
        ?string $errorMessage = null,
    ): void {
        AliExpressImportLog::create([
            'aliexpress_import_id' => $import->id,
            'aliexpress_account_id' => $account->id,
            'aliexpress_product_id' => $productIdOverride ?? $dto?->productId,
            'product_title' => $dto?->subject,
            'action' => $action,
            'reason' => $reason,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Persist a seller-reviewed staged import batch from the AliExpress review
     * screen. Each item carries customised title, price, and stock chosen by
     * the seller — they are saved directly without fetching live AliExpress data.
     *
     * Invariant: ALL persisted products are forced to is_active = false.
     * The seller must explicitly activate them via PATCH /products/{id}/status.
     *
     * @param  int  $sellerProfileId  Profile ID of the authenticated seller
     * @param  array  $stagedItems  Validated products[] array from AliExpressStagedImportRequest
     * @return array{imported: int, skipped: int, products: array<int, array>}
     */
    public function importStagedProducts(int $sellerProfileId, array $stagedItems, ?AliExpressImport $import = null): array
    {
        $imported = 0;
        $skipped = 0;
        $records = [];

        $accountId = $import?->aliexpress_account_id ?? AliExpressAccount::where('profile_id', $sellerProfileId)->value('id');

        DB::transaction(function () use (
            $sellerProfileId,
            $stagedItems,
            $import,
            $accountId,
            &$imported,
            &$skipped,
            &$records,
        ) {
            foreach ($stagedItems as $item) {
                $aliexpressId = (string) $item['aliexpress_product_id'];

                // Skip duplicates already owned by this seller to ensure idempotency.
                $exists = Product::where('source', 'aliexpress')
                    ->where('seller_id', $sellerProfileId)
                    ->where('aliexpress_product_id', $aliexpressId)
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                $stockQty = (int) ($item['stock'] ?? $item['stock_quantity'] ?? 0);
                $categoryId = $item['category_id'] ?? null;
                if (! $categoryId && ! empty($item['category_name'])) {
                    $cat = ProductCategory::firstOrCreate(
                        ['name' => trim($item['category_name'])],
                        ['slug' => Str::slug($item['category_name']), 'product_type' => 'physical', 'is_active' => true]
                    );
                    $categoryId = $cat->id;
                }

                $images = $item['images'] ?? [];
                if (is_string($images)) {
                    $images = json_decode($images, true) ?: [$images];
                }

                $shippingCost = isset($item['shipping_cost']) ? (float) $item['shipping_cost'] : 0.0;
                $isFreeShipping = $shippingCost <= 0;
                $shippingFee = $item['shipping_fee'] ?? ($isFreeShipping ? 'Free Shipping' : ('$'.number_format($shippingCost, 2)));
                $carrier = $item['carrier'] ?? 'AliExpress Standard Shipping';
                $reviewsCount = isset($item['reviews_count']) ? (int) $item['reviews_count'] : (isset($item['reviews']) ? (int) $item['reviews'] : 0);

                $product = Product::create([
                    'seller_id' => $sellerProfileId,
                    'source' => 'aliexpress',
                    'aliexpress_product_id' => $aliexpressId,
                    'aliexpress_account_id' => $accountId,
                    'aliexpress_product_url' => $item['product_url'] ?? "https://www.aliexpress.com/item/{$aliexpressId}.html",
                    'title' => $item['title'],
                    'description' => $item['description'] ?? null,
                    'price' => (float) $item['price'],
                    'currency' => $item['currency'] ?? 'USD',
                    'category_id' => $categoryId,
                    'stock_quantity' => $stockQty,
                    'shipping_cost' => $shippingCost ?: null,
                    'image_url' => $item['image_url'] ?? $item['image'] ?? null,
                    'images' => $images !== [] ? $images : null,
                    'product_variants' => $item['product_variants'] ?? null,
                    'is_active' => false,
                    'aliexpress_source_data' => [
                        'cost_price' => isset($item['supplier_cost']) ? (float) $item['supplier_cost'] : null,
                        'supplier_cost' => isset($item['supplier_cost']) ? (float) $item['supplier_cost'] : null,
                        'profit_markup' => isset($item['profit_markup']) ? (float) $item['profit_markup'] : null,
                        'price_min' => $item['price_min'] ?? number_format((float) $item['price'], 2, '.', ''),
                        'price_max' => $item['price_max'] ?? number_format((float) $item['price'], 2, '.', ''),
                        'original_price' => $item['original_price'] ?? $item['original_price_min'] ?? null,
                        'original_price_min' => $item['original_price_min'] ?? $item['original_price'] ?? null,
                        'original_price_max' => $item['original_price_max'] ?? $item['original_price'] ?? null,
                        'shipping' => $item['shipping'] ?? null,
                        'shipping_cost' => $shippingCost,
                        'shipping_fee' => $shippingFee,
                        'is_free_shipping' => $isFreeShipping,
                        'carrier' => $carrier,
                        'package' => $item['package'] ?? null,
                        'logistics' => $item['logistics'] ?? null,
                        'ship_to_country' => $item['ship_to_country'] ?? 'US',
                        'rating' => isset($item['rating']) && (float) $item['rating'] > 0 ? (float) $item['rating'] : null,
                        'reviews_count' => $reviewsCount ?: null,
                        'reviews' => $reviewsCount ?: null,
                        'reviews_desc' => $item['reviews_desc'] ?? ($reviewsCount > 0 ? number_format($reviewsCount).' Reviews' : '0 Reviews'),
                        'orders' => isset($item['orders']) ? (int) $item['orders'] : null,
                        'sold' => $item['sold'] ?? ($item['sold_desc'] ?? null),
                        'sold_desc' => $item['sold'] ?? ($item['sold_desc'] ?? null),
                        'videos' => $item['videos'] ?? [],
                        'brand' => $item['brand'] ?? null,
                        'attributes' => $item['attributes'] ?? null,
                        'skus' => $item['skus'] ?? null,
                        'staged_import' => true,
                    ],
                ]);

                if ($import && $accountId) {
                    AliExpressImportLog::create([
                        'aliexpress_import_id' => $import->id,
                        'aliexpress_account_id' => $accountId,
                        'aliexpress_product_id' => $aliexpressId,
                        'product_title' => $item['title'],
                        'action' => AliExpressImportLog::ACTION_IMPORTED,
                    ]);
                }

                $remoteImages = array_values(array_filter($images, function ($img) {
                    return is_string($img) && (str_starts_with($img, 'http://') || str_starts_with($img, 'https://'));
                }));

                if (! empty($remoteImages) && $accountId) {
                    $acc = $import?->account ?? AliExpressAccount::find($accountId);
                    if ($acc) {
                        AliExpressImageDownloadJob::dispatch(
                            accountId: $acc->id,
                            productId: $product->id,
                            urls: $remoteImages,
                            mainUrl: (is_string($item['image_url'] ?? null) && str_starts_with($item['image_url'], 'http')) ? $item['image_url'] : ($remoteImages[0] ?? null),
                        );
                    }
                }

                $records[] = [
                    'product_id' => $product->id,
                    'aliexpress_product_id' => $aliexpressId,
                    'title' => $product->title,
                    'price' => (float) $product->price,
                    'stock_quantity' => $product->stock_quantity,
                    'image_url' => $product->image_url,
                    'is_active' => false,
                ];

                $imported++;
            }
        });

        return compact('imported', 'skipped', 'records');
    }
}
