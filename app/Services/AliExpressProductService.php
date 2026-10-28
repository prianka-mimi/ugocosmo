<?php

namespace App\Services;

use App\DTOs\AliExpress\AliExpressProductDTO;
use App\Exceptions\AliExpress\AliExpressApiException;
use App\Models\AliExpressAccount;
use Illuminate\Support\Facades\Log;

/**
 * Fetches products from the AliExpress DS (Dropshipping) API.
 *
 * Approved APIs for this app:
 *   aliexpress.ds.product.get       — single product detail  ✅
 *   aliexpress.ds.feed.itemids.get  — product IDs from a feed ✅
 *   aliexpress.ds.feedname.get      — available feed names    ✅
 *   aliexpress.ds.product.search    — keyword search          ❌ (not approved)
 *
 * Browse flow: feedname.get → feed.itemids.get → product.get (per ID)
 */
class AliExpressProductService
{
    public function __construct(
        protected AliExpressApiClient $client,
        protected AliExpressTokenManager $tokens,
    ) {}

    /**
     * Get available feed names via aliexpress.ds.feedname.get.
     */
    public function getFeedNames(AliExpressAccount $account): array
    {
        $token = $this->tokens->ensureValidToken($account);

        $response = $this->client->call(
            'aliexpress.ds.feedname.get',
            [],
            $token,
            $account->region,
            $account->id
        );

        $result = $response['resp_result']['result'] ?? $response['result'] ?? $response;
        $promos = $result['promos'] ?? [];

        if (! is_array($promos) || empty($promos)) {
            return [];
        }

        if (! array_is_list($promos)) {
            $promos = [$promos];
        }

        return array_map(fn (array $p) => [
            'feed_name' => (string) ($p['promo_name'] ?? ''),
            'label' => (string) ($p['promo_desc'] ?? $p['promo_name'] ?? ''),
            'product_count' => (int) ($p['product_num'] ?? 0),
        ], $promos);
    }

    /**
     * Browse products from a feed via aliexpress.ds.feed.itemids.get,
     * then fetch full detail for each ID via aliexpress.ds.product.get.
     */
    public function browseProducts(AliExpressAccount $account, string $feedName, int $pageSize = 20, ?string $searchId = null): array
    {
        $token = $this->tokens->ensureValidToken($account);

        $params = [
            'feed_name' => $feedName,
            'page_size' => min($pageSize, 200),
        ];

        if ($searchId !== null) {
            $params['search_id'] = $searchId;
        }

        $response = $this->client->call(
            'aliexpress.ds.feed.itemids.get',
            $params,
            $token,
            $account->region,
            $account->id,
            true  // flat params — this API rejects param_json
        );

        $result = $response['resp_result']['result'] ?? $response['result'] ?? $response;
        $rawProducts = $result['products'] ?? [];

        $productIds = array_filter(array_map(function ($item) {
            return is_array($item)
                ? ($item['item_id'] ?? $item['product_id'] ?? $item['id'] ?? null)
                : $item;
        }, (array) $rawProducts));

        $total = (int) ($result['total'] ?? count($productIds));
        $nextSearchId = $result['search_id'] ?? null;
        $shipTo = config('services.aliexpress.ship_to_country', 'US');

        $products = [];
        $shipTo = 'US';

        // Fetch product details concurrently to reduce response time
        $pool = array_slice($productIds, 0, $pageSize);
        foreach ($pool as $id) {
            try {
                $products[] = $this->dtoToListItem($this->getProduct($account, (string) $id, $shipTo));
            } catch (\Throwable $e) {
                Log::warning('AliExpress browseProducts: product.get failed', [
                    'product_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'products' => $products,
            'total' => $total,
            'page_size' => $pageSize,
            'feed_name' => $feedName,
            'search_id' => $nextSearchId,
        ];
    }

    /**
     * Search products via aliexpress.ds.product.search.
     * NOTE: requires Search API approval in the Open Platform console.
     */
    public function searchProducts(AliExpressAccount $account, string $keyword, int $page = 1, int $pageSize = 20): array
    {
        $token = $this->tokens->ensureValidToken($account);

        $response = $this->client->call(
            'aliexpress.ds.product.search',
            [
                'keywords' => $keyword,
                'page_no' => $page,
                'page_size' => min($pageSize, 50),
                'sort' => 'LAST_VOLUME_DESC',
            ],
            $token,
            $account->region,
            $account->id
        );

        // Official response: aliexpress_ds_product_search_response.result
        $result = $response['result'] ?? $response;
        $rawList = $result['products']['product'] ?? $result['product_list'] ?? $result['products'] ?? [];

        if (! is_array($rawList)) {
            $rawList = [];
        }
        if (! empty($rawList) && ! array_is_list($rawList)) {
            $rawList = [$rawList];
        }

        $products = array_map(fn (array $item) => [
            'product_id' => (string) ($item['product_id'] ?? ''),
            'title' => (string) ($item['product_title'] ?? $item['subject'] ?? ''),
            'image' => (string) ($item['product_main_image_url'] ?? $item['image_url'] ?? ''),
            'price_min' => (string) ($item['target_sale_price'] ?? $item['sale_price'] ?? ''),
            'price_max' => (string) ($item['target_original_price'] ?? $item['original_price'] ?? ''),
            'currency' => (string) ($item['target_sale_price_currency'] ?? $item['currency_code'] ?? 'USD'),
            'product_url' => (string) ($item['product_detail_url'] ?? (isset($item['product_id']) ? "https://www.aliexpress.com/item/{$item['product_id']}.html" : '')),
            'orders' => (int) ($item['lastest_volume'] ?? 0),
            'rating' => (string) ($item['evaluate_rate'] ?? ''),
        ], $rawList);

        return [
            'products' => $products,
            'total' => (int) ($result['total_record_count'] ?? $result['total'] ?? count($products)),
            'page' => $page,
            'page_size' => $pageSize,
            'keyword' => $keyword,
        ];
    }

    /**
     * Fetch a single product via aliexpress.ds.product.get (approved ✅).
     */
    public function getProduct(AliExpressAccount $account, int|string $productId, ?string $shipToCountry = null): AliExpressProductDTO
    {
        $token = $this->tokens->ensureValidToken($account);

        $response = $this->client->call(
            config('services.aliexpress.methods.product_detail'),
            [
                'product_id' => (int) $productId,
                'ship_to_country' => $shipToCountry ?: 'US',
            ],
            $token,
            $account->region,
            $account->id,
            true  // flat params — ds.product.get rejects param_json
        );

        $result = $response['result'] ?? $response;

        $hasData = ! empty($result['ae_item_base_info_dto'])
            || ! empty($result['ae_item_sku_info_dtos'])
            || ! empty($result['product_id']);

        if (! is_array($result) || ! $hasData) {
            throw new AliExpressApiException(
                "AliExpress returned no detail for product {$productId}.",
                502,
                null,
                $response
            );
        }

        if (isset($result['ae_item_base_info_dto']) || isset($result['ae_multimedia_info_dto']) || isset($result['ae_item_sku_info_dtos'])) {
            return AliExpressProductDTO::fromDsResponse($result);
        }

        return AliExpressProductDTO::fromMerchantDetail($result);
    }

    private function dtoToListItem(AliExpressProductDTO $dto): array
    {
        return [
            'product_id' => $dto->productId,
            'title' => $dto->subject,
            'image' => $dto->mainImage,
            'price_min' => $dto->priceMin,
            'price_max' => $dto->priceMax,
            'currency' => $dto->currency,
            'product_url' => $dto->productUrl,
            'stock' => $dto->totalStock,
            'orders' => $dto->orders,
            'rating' => $dto->rating,
        ];
    }
}
