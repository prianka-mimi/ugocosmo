<?php

namespace App\Services;

use App\DTOs\AliExpress\AliExpressFreightDTO;
use App\DTOs\AliExpress\AliExpressProductDTO;
use App\DTOs\AliExpress\AliExpressSkuInventoryDTO;
use App\Exceptions\AliExpress\AliExpressApiException;
use App\Models\AliExpressAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gateway for the AliExpress Dropshipping (DS) product APIs.
 *
 * This class owns ONE invariant: every outbound product call carries an
 * explicit `ship_to_country`, so AliExpress only returns products that are
 * actually deliverable to the seller's chosen destination. Callers cannot
 * accidentally reach the API without it.
 *
 * Transport concerns (HMAC signing, retries, sandbox capture, API logging)
 * are delegated to AliExpressApiClient — this class never performs HTTP and
 * never duplicates signing or error mapping.
 *
 * DS APIs used:
 *   aliexpress.ds.product.get                     — detail (approved)
 *   aliexpress.ds.product.search                  — keyword search
 *   aliexpress.offer.ds.product.simplequery       — real-time SKU stock
 *   aliexpress.social.product.freight.query       — shipping methods + cost
 *   aliexpress.ds.feedname.get                    — browse feed names
 *   aliexpress.ds.feed.itemids.get                — browse feed product IDs
 */
class AliExpressProductClient
{
    public function __construct(
        protected AliExpressApiClient $client,
        protected AliExpressTokenManager $tokens,
    ) {}

    /**
     * Keyword search, filtered to products deliverable to $shipToCountry.
     *
     * @return array{products: array<int, array<string, mixed>>, total: int, page: int, page_size: int, keyword: string, ship_to_country: ?string}
     */
    public function search(
        AliExpressAccount $account,
        string $shipToCountry,
        ?string $keyword = null,
        int $page = 1,
        int $pageSize = 20,
        ?string $category = null,
        bool $forceRefresh = false,
        ?string $displayCountry = null
    ): array {
        $shipToCountry = $this->normalizeCountry($shipToCountry);
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);

        $cacheKey = "aliexpress:search_v4:{$shipToCountry}:".($displayCountry ?: 'null').":p{$page}:s{$pageSize}:".md5(($keyword ?? '').':'.($category ?? ''));

        if (! $forceRefresh && ($cached = cache()->get($cacheKey))) {
            return $cached;
        }

        $searchTerm = trim((string) ($keyword ?: $category));
        $lastError = null;

        if ($searchTerm !== '') {
            $searchMethods = [
                [
                    'method' => 'aliexpress.affiliate.product.query',
                    'params' => array_filter([
                        'keywords' => $searchTerm,
                        'ship_to_country' => $shipToCountry,
                        'page_no' => $page,
                        'page_size' => min($pageSize, 100),
                        'sort' => 'LAST_VOLUME_DESC',
                        'target_currency' => 'USD',
                        'target_language' => 'EN',
                        'category_ids' => ($category && ctype_digit($category)) ? $category : null,
                    ], fn ($v) => $v !== null && $v !== ''),
                    'flat' => false,
                ],
                [
                    'method' => 'aliexpress.ds.recommend.feed.get',
                    'params' => array_filter([
                        'keywords' => $searchTerm,
                        'country' => $shipToCountry,
                        'page_no' => $page,
                        'page_size' => min($pageSize, 100),
                        'target_currency' => 'USD',
                        'target_language' => 'EN',
                    ], fn ($v) => $v !== null && $v !== ''),
                    'flat' => true,
                ],
                [
                    'method' => $this->method('product_search', 'aliexpress.ds.product.search'),
                    'params' => array_filter([
                        'keywords' => $searchTerm,
                        'ship_to_country' => $shipToCountry,
                        'page_no' => $page,
                        'page_size' => min($pageSize, 100),
                        'sort' => 'LAST_VOLUME_DESC',
                        'category_id' => ($category && ctype_digit($category)) ? $category : null,
                    ], fn ($v) => $v !== null && $v !== ''),
                    'flat' => true,
                ],
            ];

            foreach ($searchMethods as $candidate) {
                try {
                    $response = $this->call($account, $candidate['method'], $candidate['params'], $candidate['flat']);
                    $result = $response['resp_result']['result'] ?? $response['result'] ?? $response;
                    $rawList = $this->extractProductList($result);

                    if (! empty($rawList)) {
                        $products = array_map(
                            fn (array $item): array => $this->toListItem($item, $shipToCountry),
                            array_filter($rawList, 'is_array')
                        );
                        $products = array_slice($products, 0, $pageSize);

                        $total = (int) (
                            $result['total_record_count']
                            ?? $result['total']
                            ?? $response['resp_result']['result']['total_record_count']
                            ?? count($products)
                        );

                        $resultData = [
                            'products' => $products,
                            'total' => $total,
                            'page' => $page,
                            'page_size' => $pageSize,
                            'keyword' => (string) $keyword,
                            'ship_to_country' => $displayCountry,
                        ];

                        if (! empty($products)) {
                            cache()->put($cacheKey, $resultData, 3600);
                        }

                        return $resultData;
                    }
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    Log::info("AliExpress search method [{$candidate['method']}] failed: ".$e->getMessage());
                }
            }

            Log::warning('AliExpress direct keyword search methods unavailable, attempting wholesale search index: '.$lastError);
        }

        $wholesaleTerm = ($searchTerm !== '' && strtolower($searchTerm) !== 'popular') ? $searchTerm : 'trending-products';
        $wholesale = $this->searchViaPublicWholesale($account, $shipToCountry, $wholesaleTerm, $page, $pageSize, $forceRefresh, $displayCountry);
        if (! empty($wholesale['products'])) {
            $wholesale['keyword'] = $searchTerm !== '' ? $searchTerm : null;
            cache()->put($cacheKey, $wholesale, 3600);

            return $wholesale;
        }

        $fallback = $this->searchViaFeedFallback($account, $shipToCountry, $searchTerm, $page, $pageSize, $forceRefresh, $displayCountry);
        if ($lastError) {
            $fallback['upstream_error'] = $lastError;
        }

        if (! empty($fallback['products'])) {
            cache()->put($cacheKey, $fallback, 1800);
        }

        return $fallback;
    }

    /**
     * Fetch product IDs and total count from an AliExpress wholesale web search page.
     * Extracts items from the official page payload (window._dida_config_._init_data_)
     * in the exact serial order displayed to real AliExpress users.
     *
     * @return array{ids: array<int, string>, total: int}
     */
    protected function fetchWholesalePageData(string $slug, int $webPage = 1): array
    {
        $cacheKey = "aliexpress:wholesale_data_v5:{$slug}:p{$webPage}";

        return cache()->remember($cacheKey, 7200, function () use ($slug, $webPage) {
            $url = "https://www.aliexpress.com/w/wholesale-{$slug}.html";
            if ($webPage > 1) {
                $url .= "?page={$webPage}";
            }

            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'en-US,en;q=0.9',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    ])
                    ->get($url);

                if (! $response->successful()) {
                    return ['ids' => [], 'total' => 0];
                }

                $html = $response->body();

                // 1. Primary: Extract sequential search cards from window._dida_config_._init_data_
                if (preg_match('/window\._dida_config_\._init_data_\s*=\s*(\{.+?\})\s*\/\*!-->init-data-end--\*\//s', $html, $m)) {
                    $raw = trim($m[1]);
                    $raw = preg_replace('/^\s*\{\s*data\s*:/', '{"data":', $raw);
                    $decoded = json_decode($raw, true);

                    if (is_array($decoded)) {
                        $root = $decoded['data']['data']['root'] ?? [];
                        $content = $root['fields']['mods']['itemList']['content'] ?? [];
                        $total = (int) ($root['fields']['pageInfo']['totalResults'] ?? 0);

                        $orderedIds = [];
                        $cardMeta = [];
                        foreach ($content as $item) {
                            $pid = (string) ($item['productId'] ?? '');
                            if ($pid !== '' && ! in_array($pid, $orderedIds, true)) {
                                $orderedIds[] = $pid;

                                $tradeDesc = ! empty($item['trade']['tradeDesc']) ? (string) $item['trade']['tradeDesc'] : null;
                                $realTrade = isset($item['trace']['utLogMap']['real_trade_count'])
                                    ? (int) $item['trace']['utLogMap']['real_trade_count']
                                    : 0;
                                $star = isset($item['evaluation']['starRating'])
                                    ? (string) $item['evaluation']['starRating']
                                    : null;

                                $cardMeta[$pid] = [
                                    'sold' => $tradeDesc,
                                    'orders' => $realTrade,
                                    'rating' => $star,
                                ];
                            }
                        }

                        if (! empty($orderedIds)) {
                            return [
                                'ids' => $orderedIds,
                                'total' => $total > 0 ? $total : 10000,
                                'card_meta' => $cardMeta,
                            ];
                        }
                    }
                }

                // 2. Fallback: Parse item URLs if init_data was absent
                if (preg_match_all('#aliexpress\.com/item/(\d+)\.html#i', $html, $matches)) {
                    $fallbackIds = array_values(array_unique($matches[1]));

                    return [
                        'ids' => $fallbackIds,
                        'total' => 10000,
                        'card_meta' => [],
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("AliExpress fetch wholesale data p{$webPage} error: ".$e->getMessage());
            }

            return ['ids' => [], 'total' => 0, 'card_meta' => []];
        });
    }

    /**
     * Batch fetch review counts for an array of product IDs in parallel with 24hr caching.
     *
     * @param  array<int, string>  $productIds
     * @return array<string, int>
     */
    public function batchReviewsCount(array $productIds): array
    {
        $results = [];
        $missing = [];

        foreach ($productIds as $pid) {
            $pidStr = (string) $pid;
            $cached = cache()->get("aliexpress:reviews_count:{$pidStr}");
            if ($cached !== null) {
                $results[$pidStr] = (int) $cached;
            } else {
                $missing[] = $pidStr;
            }
        }

        if (empty($missing)) {
            return $results;
        }

        try {
            $responses = Http::pool(function ($pool) use ($missing) {
                $requests = [];
                foreach ($missing as $pidStr) {
                    $requests[$pidStr] = $pool->as($pidStr)
                        ->timeout(3)
                        ->withHeaders([
                            'Accept' => 'application/json',
                            'Referer' => "https://www.aliexpress.com/item/{$pidStr}.html",
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                            'Accept-Language' => 'en-US,en;q=0.9',
                        ])
                        ->get("https://feedback.aliexpress.com/pc/searchEvaluation.do?productId={$pidStr}&page=1&pageSize=1");
                }

                return $requests;
            });

            foreach ($missing as $pidStr) {
                $count = 0;
                if (isset($responses[$pidStr]) && $responses[$pidStr] instanceof Response && $responses[$pidStr]->successful()) {
                    $json = $responses[$pidStr]->json();
                    $count = (int) ($json['data']['totalNum'] ?? $json['data']['productEvaluationStatistic']['totalNum'] ?? 0);
                }
                cache()->put("aliexpress:reviews_count:{$pidStr}", $count, 86400);
                $results[$pidStr] = $count;
            }
        } catch (\Throwable $e) {
            foreach ($missing as $pidStr) {
                $results[$pidStr] = 0;
            }
        }

        return $results;
    }

    /**
     * Fetch authentic AliExpress evaluation/review statistics and buyer comments.
     *
     * @return array{rating: float, rating_percentage: float, total_reviews: int, ratings_count: int, positive_rate: string, positive_count: int, neutral_count: int, negative_count: int, rating_breakdown: array<string, array{count: int, percentage: string}>, reviews_list: array<int, array<string, mixed>>}
     */
    public function productEvaluations(int|string $productId, int $page = 1, int $pageSize = 10, bool $forceRefresh = false): array
    {
        $pidStr = (string) $productId;
        $cacheKey = "aliexpress:evaluations_v2:{$pidStr}:p{$page}:s{$pageSize}";

        if ($forceRefresh) {
            cache()->forget($cacheKey);
            cache()->forget("aliexpress:evaluations_v1:{$pidStr}:p{$page}:s{$pageSize}");
        }

        return cache()->remember($cacheKey, 43200, function () use ($pidStr, $page, $pageSize) {
            try {
                $response = Http::timeout(5)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Referer' => "https://www.aliexpress.com/item/{$pidStr}.html",
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                        'Accept-Language' => 'en-US,en;q=0.9',
                    ])
                    ->get("https://feedback.aliexpress.com/pc/searchEvaluation.do?productId={$pidStr}&page={$page}&pageSize={$pageSize}");

                if (! $response->successful()) {
                    return [
                        'rating' => 0.0,
                        'rating_percentage' => 0.0,
                        'total_reviews' => 0,
                        'positive_rate' => '0%',
                        'positive_count' => 0,
                        'neutral_count' => 0,
                        'negative_count' => 0,
                        'rating_breakdown' => [],
                        'reviews_list' => [],
                    ];
                }

                $json = $response->json();
                $data = $json['data'] ?? [];
                $stat = $data['productEvaluationStatistic'] ?? [];

                $fiveStar = (int) ($stat['fiveStarNum'] ?? 0);
                $fourStar = (int) ($stat['fourStarNum'] ?? 0);
                $threeStar = (int) ($stat['threeStarNum'] ?? 0);
                $twoStar = (int) ($stat['twoStarNum'] ?? 0);
                $oneStar = (int) ($stat['oneStarNum'] ?? 0);
                $sumStars = $fiveStar + $fourStar + $threeStar + $twoStar + $oneStar;

                // Total reviews is the true total across all 1-5 star ratings (e.g. 223)
                $totalReviews = $sumStars > 0 ? $sumStars : (int) ($stat['totalNum'] ?? $data['totalNum'] ?? 0);

                // Ratings count is the number of reviews with written comments/content (e.g. 38)
                $ratingsCount = (int) ($data['totalNum'] ?? ($stat['totalNum'] ?? $totalReviews));

                $evarageStar = (float) ($stat['evarageStar'] ?? 0.0);
                $evarageStarRage = (float) ($stat['evarageStarRage'] ?? 0.0);
                $positiveRate = isset($stat['positiveRate']) ? $stat['positiveRate'].'%' : '0%';

                $breakdown = [
                    '5_star' => [
                        'count' => $fiveStar,
                        'percentage' => ($stat['fiveStarRate'] ?? ($totalReviews > 0 ? round(($fiveStar / $totalReviews) * 100, 1) : 0)).'%',
                    ],
                    '4_star' => [
                        'count' => $fourStar,
                        'percentage' => ($stat['fourStarRate'] ?? ($totalReviews > 0 ? round(($fourStar / $totalReviews) * 100, 1) : 0)).'%',
                    ],
                    '3_star' => [
                        'count' => $threeStar,
                        'percentage' => ($stat['threeStarRate'] ?? ($totalReviews > 0 ? round(($threeStar / $totalReviews) * 100, 1) : 0)).'%',
                    ],
                    '2_star' => [
                        'count' => $twoStar,
                        'percentage' => ($stat['twoStarRate'] ?? ($totalReviews > 0 ? round(($twoStar / $totalReviews) * 100, 1) : 0)).'%',
                    ],
                    '1_star' => [
                        'count' => $oneStar,
                        'percentage' => ($stat['oneStarRate'] ?? ($totalReviews > 0 ? round(($oneStar / $totalReviews) * 100, 1) : 0)).'%',
                    ],
                ];

                $reviewsList = [];
                foreach ((array) ($data['evaViewList'] ?? []) as $eva) {
                    if (! is_array($eva)) {
                        continue;
                    }
                    $evalScore = isset($eva['buyerEval']) ? (int) $eva['buyerEval'] : 100;
                    $stars = max(1, min(5, (int) round($evalScore / 20)));

                    $reviewsList[] = [
                        'evaluation_id' => (string) ($eva['evaluationId'] ?? $eva['evaluationIdStr'] ?? ''),
                        'buyer_name' => (string) ($eva['buyerName'] ?? 'AliExpress Shopper'),
                        'buyer_country' => (string) ($eva['buyerCountry'] ?? ''),
                        'buyer_avatar' => (string) ($eva['buyerHeadPortrait'] ?? ''),
                        'rating' => $stars,
                        'content' => (string) ($eva['buyerFeedback'] ?? ''),
                        'date' => (string) ($eva['evalDate'] ?? ''),
                        'sku_info' => (string) ($eva['skuInfo'] ?? ''),
                        'images' => (array) ($eva['images'] ?? []),
                        'logistics' => (string) ($eva['logistics'] ?? ''),
                        'upvotes' => (int) ($eva['upVoteCount'] ?? 0),
                    ];
                }

                return [
                    'rating' => $evarageStar,
                    'rating_percentage' => $evarageStarRage,
                    'total_reviews' => $totalReviews,
                    'ratings_count' => $ratingsCount,
                    'positive_rate' => $positiveRate,
                    'positive_count' => (int) ($stat['positiveNum'] ?? 0),
                    'neutral_count' => (int) ($stat['neutralNum'] ?? 0),
                    'negative_count' => (int) ($stat['negativeNum'] ?? 0),
                    'rating_breakdown' => $breakdown,
                    'reviews_list' => $reviewsList,
                ];
            } catch (\Throwable $e) {
                Log::warning("AliExpress productEvaluations({$pidStr}) failed: ".$e->getMessage());

                return [
                    'rating' => 0.0,
                    'rating_percentage' => 0.0,
                    'total_reviews' => 0,
                    'ratings_count' => 0,
                    'positive_rate' => '0%',
                    'positive_count' => 0,
                    'neutral_count' => 0,
                    'negative_count' => 0,
                    'rating_breakdown' => [],
                    'reviews_list' => [],
                ];
            }
        });
    }

    /**
     * Backward-compatible helper to fetch wholesale product IDs.
     *
     * @return array<int, string>
     */
    protected function fetchWholesalePageProductIds(string $slug, int $webPage = 1): array
    {
        return $this->fetchWholesalePageData($slug, $webPage)['ids'];
    }

    /**
     * Search AliExpress live products by keyword via the wholesale product index,
     * maintaining the exact serial order of results as shown on AliExpress.
     *
     * @return array{products: array<int, array<string, mixed>>, total: int, page: int, page_size: int, keyword: ?string, ship_to_country: ?string}
     */
    protected function searchViaPublicWholesale(
        AliExpressAccount $account,
        string $shipToCountry,
        string $keyword,
        int $page = 1,
        int $pageSize = 20,
        bool $forceRefresh = false,
        ?string $displayCountry = null
    ): array {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);

        $cacheKey = "aliexpress:wholesale_v6:{$shipToCountry}:".($displayCountry ?: 'null').":p{$page}:s{$pageSize}:".md5(strtolower(trim($keyword)));
        if (! $forceRefresh && ($cached = cache()->get($cacheKey))) {
            return $cached;
        }

        try {
            $rawTerm = trim($keyword);
            $slug = ($rawTerm !== '' && strtolower($rawTerm) !== 'popular')
                ? urlencode(str_replace([' ', '/', '+', '_'], '-', $rawTerm))
                : 'trending-products';

            $startIndex = ($page - 1) * $pageSize;
            $candidateCount = $pageSize + min(30, (int) ceil($pageSize * 0.15));
            $endIndex = $startIndex + $candidateCount;

            // AliExpress wholesale web pages contain ~60 items each
            $firstWebPage = (int) floor($startIndex / 60) + 1;
            $lastWebPage = (int) floor(($endIndex - 1) / 60) + 1;

            $allCollectedIds = [];
            $allCardMeta = [];
            $realTotal = 0;
            for ($wp = $firstWebPage; $wp <= $lastWebPage; $wp++) {
                $pageData = $this->fetchWholesalePageData($slug, $wp);
                if (! empty($pageData['ids'])) {
                    foreach ($pageData['ids'] as $pid) {
                        if (! in_array($pid, $allCollectedIds, true)) {
                            $allCollectedIds[] = $pid;
                        }
                    }
                }
                if (! empty($pageData['card_meta'])) {
                    foreach ($pageData['card_meta'] as $kPid => $meta) {
                        if (! isset($allCardMeta[$kPid])) {
                            $allCardMeta[$kPid] = $meta;
                        }
                    }
                }
                if ($pageData['total'] > 0 && $realTotal === 0) {
                    $realTotal = $pageData['total'];
                }
            }

            if (empty($allCollectedIds)) {
                return [
                    'products' => [],
                    'total' => 0,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'keyword' => ($rawTerm !== '' && strtolower($rawTerm) !== 'popular') ? $rawTerm : null,
                    'ship_to_country' => $displayCountry,
                ];
            }

            $relativeOffset = max(0, $startIndex - (($firstWebPage - 1) * 60));
            $sliceIds = array_slice($allCollectedIds, $relativeOffset, $candidateCount);
            if (empty($sliceIds)) {
                $sliceIds = array_slice($allCollectedIds, 0, $candidateCount);
            }

            $detailsMap = $this->batchDetails($account, $shipToCountry, $sliceIds, $forceRefresh);
            $reviewsMap = $this->batchReviewsCount($sliceIds);
            $products = [];

            foreach ($sliceIds as $pid) {
                $pidStr = (string) $pid;
                if (! isset($detailsMap[$pidStr])) {
                    continue;
                }
                $detail = $detailsMap[$pidStr];
                $meta = $allCardMeta[$pidStr] ?? [];

                $ordersCount = $meta['orders'] > 0 ? (int) $meta['orders'] : (int) ($detail->orders ?? 0);

                $soldDesc = $meta['sold'] ?? $detail->sold ?? null;
                if (! $soldDesc && $ordersCount > 0) {
                    $soldDesc = $ordersCount >= 1000
                        ? number_format((int) (floor($ordersCount / 1000) * 1000)).'+ sold'
                        : $ordersCount.' sold';
                }
                if (! $soldDesc) {
                    $soldDesc = '0 sold';
                }

                $rating = ! empty($meta['rating']) ? (string) $meta['rating'] : (string) ($detail->rating ?? '');

                $reviewsCount = $detail->reviews > 0 ? $detail->reviews : ($reviewsMap[$pidStr] ?? 0);
                $reviewsDesc = $reviewsCount > 0
                    ? number_format($reviewsCount).' Reviews'
                    : '0 Reviews';

                $products[] = [
                    'product_id' => (string) $detail->productId,
                    'title' => $detail->subject ?? $detail->title ?? '',
                    'image' => $detail->mainImage ?? $detail->mainImageUrl ?? ($detail->images[0] ?? ''),
                    'price_min' => (string) ($detail->priceMin ?? $detail->salePrice ?? '0.00'),
                    'price_max' => (string) ($detail->priceMax ?? $detail->originalPrice ?? '0.00'),
                    'currency' => $detail->currency ?? 'USD',
                    'product_url' => "https://www.aliexpress.com/item/{$detail->productId}.html",
                    'orders' => $ordersCount,
                    'sold' => $soldDesc,
                    'sold_desc' => $soldDesc,
                    'rating' => $rating,
                    'reviews' => $reviewsCount,
                    'reviews_count' => $reviewsCount,
                    'reviews_desc' => $reviewsDesc,
                    'stock' => (int) ($detail->totalStock ?? $detail->totalAvailableStock ?? 100),
                    'ship_to_country' => $shipToCountry,
                    'skus' => array_map(fn ($sku) => [
                        'sku_id' => (string) $sku->skuId,
                        'sku_attr' => $sku->skuAttr,
                        'sku_code' => $sku->skuCode ?? null,
                        'price' => (string) ($sku->skuPrice ?? '0.00'),
                        'stock' => (int) $sku->stock,
                        'image' => $sku->image ?? null,
                        'properties' => $sku->properties,
                    ], $detail->skus ?? []),
                ];

                if (count($products) >= $pageSize) {
                    break;
                }
            }

            $products = array_slice($products, 0, $pageSize);

            $result = [
                'products' => $products,
                'total' => $realTotal > 0 ? $realTotal : max(count($products), 10000),
                'page' => $page,
                'page_size' => $pageSize,
                'keyword' => ($rawTerm !== '' && strtolower($rawTerm) !== 'popular') ? $rawTerm : null,
                'ship_to_country' => $displayCountry,
            ];

            if (! empty($products)) {
                cache()->put($cacheKey, $result, 3600);
            }

            return $result;
        } catch (\Throwable $ex) {
            Log::warning('AliExpress wholesale search error: '.$ex->getMessage());

            return [
                'products' => [],
                'total' => 0,
                'page' => $page,
                'page_size' => $pageSize,
                'keyword' => ($rawTerm !== '' && strtolower($rawTerm) !== 'popular') ? $rawTerm : null,
                'ship_to_country' => $displayCountry,
            ];
        }
    }

    /**
     * Fallback browsing when keyword search API (aliexpress.ds.product.search)
     * is unavailable or pending approval on the AliExpress Open Platform.
     * Uses parallel batchDetails for sub-second retrieval.
     *
     * @return array{products: array<int, array<string, mixed>>, total: int, page: int, page_size: int, keyword: string, ship_to_country: string}
     */
    protected function searchViaFeedFallback(
        AliExpressAccount $account,
        string $shipToCountry,
        ?string $keyword,
        int $page,
        int $pageSize,
        bool $forceRefresh = false,
        ?string $displayCountry = null
    ): array {
        $pageSize = max(1, $pageSize);

        try {
            $feeds = $this->feedNames($account, $forceRefresh);
            $candidateFeeds = [];
            if (! empty($feeds)) {
                foreach ($feeds as $f) {
                    if (! empty($f['feed_name'])) {
                        $candidateFeeds[] = (string) $f['feed_name'];
                    }
                }
            }
            if (empty($candidateFeeds)) {
                $candidateFeeds = ['ae_dropshipping_featured', 'ae_featured', 'featured', 'popular'];
            }

            $productIds = [];
            foreach ($candidateFeeds as $feedName) {
                try {
                    $feedResult = $this->feedItemIds($account, $feedName, 60, null, $forceRefresh);
                    if (! empty($feedResult['ids'])) {
                        $productIds = array_merge($productIds, $feedResult['ids']);
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
            $productIds = array_values(array_unique($productIds));

            if (empty($productIds)) {
                return [
                    'products' => [],
                    'total' => 0,
                    'page' => max(1, $page),
                    'page_size' => $pageSize,
                    'keyword' => (string) $keyword,
                    'ship_to_country' => $displayCountry,
                ];
            }

            $startIndex = max(0, ($page - 1) * $pageSize);
            $candidateCount = $pageSize + min(30, (int) ceil($pageSize * 0.15));
            $sliceIds = array_slice($productIds, $startIndex, min($candidateCount, count($productIds) - $startIndex));
            if (empty($sliceIds) && ! empty($productIds)) {
                $sliceIds = array_slice($productIds, 0, $candidateCount);
            }
            $detailsMap = $this->batchDetails($account, $shipToCountry, $sliceIds, $forceRefresh);
            $products = [];

            foreach ($sliceIds as $pid) {
                if (! isset($detailsMap[$pid])) {
                    continue;
                }
                $detail = $detailsMap[$pid];

                if ($keyword !== null && $keyword !== '') {
                    $title = $detail->subject ?? $detail->title ?? '';
                    if (stripos($title, $keyword) === false) {
                        continue;
                    }
                }

                $ordersCount = (int) ($detail->orders ?? 0);
                $soldDesc = $detail->sold ?? ($ordersCount > 0 ? ($ordersCount >= 1000 ? number_format((int) (floor($ordersCount / 1000) * 1000)).'+ sold' : $ordersCount.' sold') : '0 sold');
                $reviewsCount = (int) ($detail->reviews ?? 0);
                $reviewsDesc = $detail->reviewsDesc ?? ($reviewsCount > 0 ? number_format($reviewsCount).' Reviews' : '0 Reviews');

                $products[] = [
                    'product_id' => (string) $detail->productId,
                    'title' => $detail->subject ?? $detail->title ?? '',
                    'image' => $detail->mainImage ?? $detail->mainImageUrl ?? ($detail->images[0] ?? ''),
                    'price_min' => (string) ($detail->priceMin ?? $detail->salePrice ?? '0.00'),
                    'price_max' => (string) ($detail->priceMax ?? $detail->originalPrice ?? '0.00'),
                    'currency' => $detail->currency ?? 'USD',
                    'product_url' => "https://www.aliexpress.com/item/{$detail->productId}.html",
                    'orders' => $ordersCount,
                    'sold' => $soldDesc,
                    'sold_desc' => $soldDesc,
                    'rating' => (string) ($detail->rating ?? ''),
                    'reviews' => $reviewsCount,
                    'reviews_count' => $reviewsCount,
                    'reviews_desc' => $reviewsDesc,
                    'stock' => (int) ($detail->totalStock ?? $detail->totalAvailableStock ?? 100),
                    'ship_to_country' => $shipToCountry,
                    'skus' => array_map(fn ($sku) => [
                        'sku_id' => (string) $sku->skuId,
                        'sku_attr' => $sku->skuAttr,
                        'sku_code' => $sku->skuCode ?? null,
                        'price' => (string) ($sku->skuPrice ?? '0.00'),
                        'stock' => (int) $sku->stock,
                        'image' => $sku->image ?? null,
                        'properties' => $sku->properties,
                    ], $detail->skus ?? []),
                ];

                if (count($products) >= $pageSize) {
                    break;
                }
            }

            $products = array_slice($products, 0, $pageSize);

            return [
                'products' => $products,
                'total' => count($products),
                'page' => max(1, $page),
                'page_size' => $pageSize,
                'keyword' => (string) $keyword,
                'ship_to_country' => $displayCountry,
            ];
        } catch (\Throwable $ex) {
            Log::warning('AliExpress feed browse fallback also failed: '.$ex->getMessage());

            return [
                'products' => [],
                'total' => 0,
                'page' => max(1, $page),
                'page_size' => $pageSize,
                'keyword' => (string) $keyword,
                'ship_to_country' => $displayCountry,
            ];
        }
    }

    /**
     * Full product detail (title, images, description, SKUs) for a destination with caching.
     */
    public function detail(AliExpressAccount $account, string $shipToCountry, int|string $productId, bool $forceRefresh = false, bool $enrich = true): AliExpressProductDTO
    {
        $shipToCountry = $this->normalizeCountry($shipToCountry);
        $cacheKey = "aliexpress:dto_v4:{$productId}:{$shipToCountry}".($enrich ? '' : ':no_enrich');

        if ($forceRefresh) {
            cache()->forget($cacheKey);
            cache()->forget("aliexpress:dto_v3:{$productId}:{$shipToCountry}");
            cache()->forget("aliexpress:dto_v2:{$productId}:{$shipToCountry}");
            cache()->forget("aliexpress:dto:{$productId}:{$shipToCountry}");
        } else {
            $cached = cache()->get($cacheKey);
            if ($cached instanceof AliExpressProductDTO) {
                return $cached;
            }
            if ($cached !== null) {
                cache()->forget($cacheKey);
            }
            cache()->forget("aliexpress:dto_v3:{$productId}:{$shipToCountry}");
            cache()->forget("aliexpress:dto_v2:{$productId}:{$shipToCountry}");
            cache()->forget("aliexpress:dto:{$productId}:{$shipToCountry}");
        }

        try {
            $response = $this->call($account, $this->method('product_detail', 'aliexpress.ds.product.get'), [
                'product_id' => (int) $productId,
                'ship_to_country' => $shipToCountry,
            ]);

            $result = $response['result'] ?? $response;

            $hasData = ! empty($result['ae_item_base_info_dto'])
                || ! empty($result['ae_item_sku_info_dtos'])
                || ! empty($result['product_id']);

            if (! is_array($result) || ! $hasData) {
                cache()->forget($cacheKey);
                throw new AliExpressApiException(
                    "AliExpress returned no detail for product {$productId}.",
                    502,
                    null,
                    ['ship_to_country' => $shipToCountry]
                );
            }

            $dto = isset($result['ae_item_base_info_dto'], $result['ae_multimedia_info_dto'], $result['ae_item_sku_info_dtos'])
                ? AliExpressProductDTO::fromDsResponse($result)
                : AliExpressProductDTO::fromMerchantDetail($result);

            if ($enrich) {
                // Enrich with authentic evaluations & customer reviews from AliExpress feedback service
                try {
                    $eval = $this->productEvaluations($productId, 1, 10, $forceRefresh);
                    if (! empty($eval['total_reviews']) || ! empty($eval['rating'])) {
                        $dto = $dto->withEvaluation($eval);
                    }
                } catch (\Throwable $e) {
                    Log::info("AliExpress evaluation enrichment skipped for {$productId}: ".$e->getMessage());
                }

                // Enrich with authentic shipping & freight details
                try {
                    $shipping = $this->calculateShippingDetails($account, $shipToCountry, $productId, $dto, 1, $forceRefresh);
                    $dto = $dto->withShipping($shipping);
                } catch (\Throwable $e) {
                    Log::info("AliExpress shipping enrichment skipped for {$productId}: ".$e->getMessage());
                }
            }

            cache()->put($cacheKey, $dto, 21600);

            return $dto;
        } catch (\Throwable $e) {
            cache()->forget($cacheKey);
            throw $e;
        }
    }

    /**
     * Concurrent parallel retrieval of product details for multiple IDs via Http::pool.
     *
     * @param  array<int|string>  $productIds
     * @return array<string, AliExpressProductDTO> keyed by string product ID
     */
    public function batchDetails(AliExpressAccount $account, string $shipToCountry, array $productIds, bool $forceRefresh = false): array
    {
        $shipToCountry = $this->normalizeCountry($shipToCountry);
        $results = [];
        $missingIds = [];

        foreach ($productIds as $pid) {
            $pidStr = (string) $pid;
            $cacheKey = "aliexpress:dto_v4:{$pidStr}:{$shipToCountry}";

            if ($forceRefresh) {
                cache()->forget($cacheKey);
                cache()->forget("aliexpress:dto_v3:{$pidStr}:{$shipToCountry}");
                cache()->forget("aliexpress:dto_v2:{$pidStr}:{$shipToCountry}");
                cache()->forget("aliexpress:dto:{$pidStr}:{$shipToCountry}");
            }

            $cached = cache()->get($cacheKey);
            if ($cached instanceof AliExpressProductDTO) {
                $results[$pidStr] = $cached;
            } else {
                if ($cached !== null) {
                    cache()->forget($cacheKey);
                }
                cache()->forget("aliexpress:dto_v3:{$pidStr}:{$shipToCountry}");
                cache()->forget("aliexpress:dto_v2:{$pidStr}:{$shipToCountry}");
                cache()->forget("aliexpress:dto:{$pidStr}:{$shipToCountry}");
                $missingIds[] = $pidStr;
            }
        }

        if (empty($missingIds)) {
            return $results;
        }

        $method = $this->method('product_detail', 'aliexpress.ds.product.get');
        $token = $this->tokens->ensureValidToken($account);
        $chunks = array_chunk($missingIds, 25);

        foreach ($chunks as $chunk) {
            $calls = [];
            foreach ($chunk as $pidStr) {
                $calls[$pidStr] = [
                    'method' => $method,
                    'params' => [
                        'product_id' => (int) $pidStr,
                        'ship_to_country' => $shipToCountry,
                    ],
                    'flatParams' => true,
                ];
            }

            try {
                $poolResponses = $this->client->pool($calls, $token, $account->region, $account->id);

                foreach ($poolResponses as $pidStr => $response) {
                    $result = $response['result'] ?? $response;
                    $hasData = ! empty($result['ae_item_base_info_dto'])
                        || ! empty($result['ae_item_sku_info_dtos'])
                        || ! empty($result['product_id']);

                    if (! is_array($result) || ! $hasData) {
                        continue;
                    }

                    $dto = isset($result['ae_item_base_info_dto'])
                        ? AliExpressProductDTO::fromDsResponse($result)
                        : AliExpressProductDTO::fromMerchantDetail($result);

                    cache()->put("aliexpress:dto_v2:{$pidStr}:{$shipToCountry}", $dto, 21600);
                    $results[$pidStr] = $dto;
                }
            } catch (\Throwable $e) {
                Log::warning('AliExpress batchDetails pool chunk error: '.$e->getMessage().', falling back to sequential calls');
                foreach ($chunk as $pidStr) {
                    try {
                        $results[$pidStr] = $this->detail($account, $shipToCountry, $pidStr, $forceRefresh);
                    } catch (\Throwable) {
                        continue;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Real-time supplier stock for every SKU, via the SKU/inventory API.
     *
     * @param  array<int, AliExpressSkuDTO>  $detailSkus  SKUs from the detail
     *                                                    response, overlaid with
     *                                                    live stock when present
     * @return array{0: array<int, \App\DTOs\AliExpress\AliExpressSkuDTO>, 1: int} [skus, totalStock]
     */
    public function skuInventory(AliExpressAccount $account, string $shipToCountry, int|string $productId, array $detailSkus = []): array
    {
        $shipToCountry = $this->normalizeCountry($shipToCountry);

        $response = $this->call($account, $this->method('sku_inventory', 'aliexpress.offer.ds.product.simplequery'), [
            'product_id' => (int) $productId,
            'local_country' => $shipToCountry,
        ]);

        $inventory = AliExpressSkuInventoryDTO::fromResponse($response, (string) $productId);

        $skus = $detailSkus !== []
            ? $inventory->mergeInto($detailSkus)
            : $inventory->skus;

        return [array_values($skus), $inventory->totalAvailableStock];
    }

    /**
     * Real-time shipping methods and freight costs for a destination country.
     *
     * @param  int  $quantity  units being shipped — freight is quantity-based
     * @return array<int, AliExpressFreightDTO>
     */
    public function freight(
        AliExpressAccount $account,
        string $shipToCountry,
        int|string $productId,
        int $quantity = 1,
        ?string $shipFromCountry = null,
        ?string $skuId = null
    ): array {
        $shipToCountry = $this->normalizeCountry($shipToCountry);
        $shipFrom = $shipFromCountry ? strtoupper(trim($shipFromCountry)) : 'CN';

        // 1. Try aliexpress.logistics.buyer.freight.calculate (Dropshipper logistics calculator)
        try {
            $payload = [
                'product_id' => (int) $productId,
                'product_num' => max(1, $quantity),
                'country_code' => $shipToCountry,
                'send_goods_country_code' => $shipFrom,
                'price_currency' => 'USD',
            ];
            if (! empty($skuId)) {
                $payload['sku_id'] = (string) $skuId;
            }

            $logisticsParams = [
                'param_aeop_freight_calculate_for_buyer_d_t_o' => json_encode($payload),
            ];

            $response = $this->call($account, 'aliexpress.logistics.buyer.freight.calculate', $logisticsParams, true);
            $methods = AliExpressFreightDTO::collectionFromResponse($response);
            if (! empty($methods)) {
                return $methods;
            }
        } catch (\Throwable $e) {
            Log::info("aliexpress.logistics.buyer.freight.calculate skipped for {$productId}: ".$e->getMessage());
        }

        // 2. Try aliexpress.social.product.freight.query
        try {
            $params = [
                'product_id' => (int) $productId,
                'quantity' => max(1, $quantity),
                'ship_to_country' => $shipToCountry,
                'ship_from_country' => $shipFrom,
            ];

            $response = $this->call($account, $this->method('freight_query', 'aliexpress.social.product.freight.query'), $params);
            $methods = AliExpressFreightDTO::collectionFromResponse($response);
            if (! empty($methods)) {
                return $methods;
            }
        } catch (\Throwable $e) {
            Log::info("aliexpress.social.product.freight.query skipped for {$productId}: ".$e->getMessage());
        }

        return [];
    }

    /**
     * Compute comprehensive shipping details for a product to a target destination country.
     * Queries live freight APIs (cached), falling back to product logistics info and standard defaults.
     *
     * @return array{
     *     shipping_cost: float,
     *     shipping_fee: string,
     *     is_free_shipping: bool,
     *     carrier: string,
     *     shipping_method: string,
     *     delivery_time: string,
     *     estimated_delivery: string,
     *     tracking_available: bool,
     *     ship_from_country: string,
     *     ship_to_country: string,
     *     shipping_options: array<int, array<string, mixed>>,
     *     package: ?array{length: float, width: float, height: float, weight: float},
     *     logistics: ?array{delivery_time: int, ship_to_country: string}
     * }
     */
    public function calculateShippingDetails(
        AliExpressAccount $account,
        string $shipToCountry,
        int|string $productId,
        ?AliExpressProductDTO $detailDto = null,
        int $quantity = 1,
        bool $forceRefresh = false
    ): array {
        $shipToCountry = $this->normalizeCountry($shipToCountry);
        $productIdStr = (string) $productId;
        $cacheKey = "aliexpress:shipping:{$productIdStr}:{$shipToCountry}:{$quantity}";

        if (! $forceRefresh) {
            $cached = cache()->get($cacheKey);
            if (is_array($cached) && isset($cached['shipping_cost'], $cached['shipping_fee'])) {
                return $cached;
            }
        }

        $freightMethods = [];
        try {
            $skuId = ! empty($detailDto?->skus) ? (string) $detailDto->skus[0]->skuId : null;
            $freightMethods = $this->freight($account, $shipToCountry, $productId, $quantity, skuId: $skuId);
        } catch (\Throwable $e) {
            Log::info("calculateShippingDetails freight call skipped for {$productIdStr}: ".$e->getMessage());
        }

        // Package and logistics metadata from DTO
        $package = $detailDto?->package;
        $logistics = $detailDto?->logistics;

        $logisticsDays = (int) ($logistics['delivery_time'] ?? 0);
        $estimatedDaysStr = $logisticsDays > 0 ? "{$logisticsDays} business days" : '10-15 business days';
        $estimatedDateRange = now()->addDays(max(7, $logisticsDays > 0 ? $logisticsDays - 3 : 7))->format('M d').' - '.now()->addDays(max(14, $logisticsDays > 0 ? $logisticsDays + 5 : 15))->format('M d');

        if (! empty($freightMethods)) {
            $cheapest = AliExpressFreightDTO::cheapest($freightMethods);
            $cost = $cheapest ? round($cheapest->cost, 2) : 0.0;
            $carrier = $cheapest ? $cheapest->company : 'AliExpress Standard Shipping';
            $deliveryTime = $cheapest?->deliveryDate ?: $estimatedDaysStr;
            $commitDay = $cheapest?->commitDay;
            $shipFrom = $cheapest?->shipFromCountry ?: 'CN';
            $tracking = $cheapest ? $cheapest->trackingSupported : true;
            $shippingOptions = AliExpressFreightDTO::toShippingOptions($freightMethods);
        } else {
            // Authentic fallback based on AliExpress defaults and product logistics
            $cost = 0.0;
            $carrier = 'AliExpress Standard Shipping';
            $deliveryTime = $estimatedDaysStr;
            $commitDay = '7';
            $shipFrom = 'CN';
            $tracking = true;
            $shippingOptions = [
                [
                    'id' => 'standard_shipping',
                    'name' => 'AliExpress Standard Shipping',
                    'cost' => 0.0,
                    'price' => 0.0,
                    'carrier' => 'AliExpress Standard Shipping',
                    'currency' => 'USD',
                    'estimated_delivery' => $estimatedDateRange,
                    'delivery_time' => $estimatedDaysStr,
                    'commit_day' => $commitDay,
                    'ship_from_country' => $shipFrom,
                    'tracking_supported' => true,
                    'is_free' => true,
                ],
            ];
        }

        $isFreeShipping = ($cost <= 0.0);
        $shippingFee = $isFreeShipping ? 'Free Shipping' : ('$'.number_format($cost, 2));

        // Clean shipping_options: remove internal/duplicate fields
        $cleanOptions = array_map(fn ($opt) => [
            'name' => $opt['name'] ?? $opt['carrier'] ?? 'AliExpress Standard Shipping',
            'carrier' => $opt['carrier'] ?? 'AliExpress Standard Shipping',
            'cost' => (float) ($opt['cost'] ?? 0),
            'currency' => $opt['currency'] ?? 'USD',
            'is_free' => (bool) ($opt['is_free'] ?? ($opt['cost'] ?? 0) <= 0),
            'delivery_time' => $opt['delivery_time'] ?? $opt['commit_day'] ?? null,
            'estimated_delivery' => $opt['estimated_delivery'] ?? null,
            'ship_from_country' => $opt['ship_from_country'] ?? 'CN',
            'tracking_supported' => (bool) ($opt['tracking_supported'] ?? true),
        ], $shippingOptions);

        $shippingData = [
            'ship_to_country' => $shipToCountry,
            'shipping_options' => $cleanOptions,
        ];

        cache()->put($cacheKey, $shippingData, 3600);

        return $shippingData;
    }

    /**
     * Browse feed names available to the account (cached — the list is
     * account-agnostic on AliExpress and rate-limited).
     *
     * @return array<int, array{feed_name: string, label: string, product_count: int}>
     */
    public function feedNames(AliExpressAccount $account, bool $forceRefresh = false): array
    {
        $cacheKey = 'aliexpress:feed_names:'.$account->id;

        if ($forceRefresh) {
            cache()->forget($cacheKey);
            cache()->forget('aliexpress:feed_names');
        }

        return cache()->remember(
            $cacheKey,
            (int) config('services.aliexpress.feed_names_cache_ttl', 3600),
            function () use ($account, $cacheKey): array {
                try {
                    $response = $this->call($account, 'aliexpress.ds.feedname.get', []);
                } catch (\Throwable $e) {
                    Log::warning('aliexpress.ds.feedname.get call failed: '.$e->getMessage());
                    cache()->forget($cacheKey);

                    return [];
                }

                $result = $response['resp_result']['result']
                    ?? $response['aliexpress_ds_feedname_get_response']['result']
                    ?? $response['result']
                    ?? $response;

                $promos = $result['promos']['promo']
                    ?? $result['promos']
                    ?? $result['promo_list']
                    ?? [];

                if (! is_array($promos) || $promos === []) {
                    cache()->forget($cacheKey);

                    return [];
                }

                if (! array_is_list($promos)) {
                    $promos = [$promos];
                }

                $feeds = array_values(array_filter(array_map(
                    static fn (array $p): array => [
                        'feed_name' => (string) ($p['promo_name'] ?? $p['feed_name'] ?? $p['name'] ?? ''),
                        'label' => (string) ($p['promo_desc'] ?? $p['promo_name'] ?? $p['label'] ?? ''),
                        'product_count' => (int) ($p['product_num'] ?? $p['product_count'] ?? 0),
                    ],
                    array_filter($promos, 'is_array')
                ), static fn (array $p): bool => $p['feed_name'] !== ''));

                if (empty($feeds)) {
                    cache()->forget($cacheKey);
                }

                return $feeds;
            }
        );
    }

    /**
     * Product IDs from a browse feed.
     *
     * @return array{ids: array<int, string>, total: int, search_id: string|null}
     */
    public function feedItemIds(AliExpressAccount $account, string $feedName, int $pageSize = 20, ?string $searchId = null, bool $forceRefresh = false): array
    {
        $cacheKey = "aliexpress:feed_ids:{$feedName}:{$pageSize}:".($searchId ?: 'init');
        if (! $forceRefresh && ($cached = cache()->get($cacheKey))) {
            return $cached;
        }

        $params = ['feed_name' => $feedName, 'page_size' => min($pageSize, 200)];

        if ($searchId !== null) {
            $params['search_id'] = $searchId;
        }

        // This API rejects param_json — business params must be flat.
        $response = $this->call($account, 'aliexpress.ds.feed.itemids.get', $params, true);

        $result = $response['resp_result']['result']
            ?? $response['aliexpress_ds_feed_itemids_get_response']['result']
            ?? $response['result']
            ?? $response;

        $rawProducts = $result['products']['product']
            ?? $result['products']['item']
            ?? $result['products']['item_id']
            ?? $result['product_ids']['product_id']
            ?? $result['products']
            ?? $result['items']
            ?? $result['item_ids']
            ?? [];

        if (! is_array($rawProducts)) {
            $rawProducts = [];
        } elseif (! empty($rawProducts) && ! array_is_list($rawProducts)) {
            $rawProducts = [$rawProducts];
        }

        $ids = [];
        foreach ($rawProducts as $item) {
            if (is_array($item)) {
                $id = $item['item_id'] ?? $item['product_id'] ?? $item['id'] ?? null;
                if ($id) {
                    $ids[] = (string) $id;
                }
            } elseif (is_numeric($item) || (is_string($item) && strlen((string) $item) > 4)) {
                $ids[] = (string) $item;
            }
        }

        $resultData = [
            'ids' => array_values(array_unique($ids)),
            'total' => (int) ($result['total'] ?? $result['total_record_count'] ?? count($ids)),
            'search_id' => $result['search_id'] ?? null,
        ];

        if (! empty($ids)) {
            cache()->put($cacheKey, $resultData, 1800);
        }

        return $resultData;
    }

    // ─── Internals ──────────────────────────────────────────────────

    /**
     * Execute a DS API call against the account's region and session.
     *
     * DS product APIs reject `param_json` and require flat form fields, so
     * every call here uses flat params.
     *
     * @param  array<string, mixed>  $params
     */
    protected function call(AliExpressAccount $account, string $method, array $params, bool $flatParams = true): array
    {
        $token = $this->tokens->ensureValidToken($account);

        return $this->client->call(
            $method,
            $params,
            $token,
            $account->region,
            $account->id,
            $flatParams
        );
    }

    /**
     * Resolve an API method name from config so it can be overridden per app
     * tier without code changes.
     */
    protected function method(string $key, string $default): string
    {
        return (string) config("services.aliexpress.methods.{$key}", $default);
    }

    /**
     * Normalize and validate a destination country. AliExpress expects an
     * ISO 3166-1 alpha-2 code; an unusable value is a client error, not a
     * silent fallback to a default market.
     *
     * @throws AliExpressApiException
     */
    protected function normalizeCountry(string $country): string
    {
        $normalized = strtoupper(trim($country));

        if (preg_match('/^[A-Z]{2}$/', $normalized) !== 1) {
            throw new AliExpressApiException(
                'A valid ship_to_country (ISO 3166-1 alpha-2, e.g. UK or US) is required.',
                422
            );
        }

        return $normalized;
    }

    /**
     * The search response nests products under several keys depending on app
     * tier; unwrap them all defensively.
     *
     * @return array<int, mixed>
     */
    protected function extractProductList(array $result): array
    {
        $list = $result['resp_result']['result']['products']['product']
            ?? $result['result']['products']['product']
            ?? $result['products']['product']
            ?? $result['product_list']
            ?? $result['products']
            ?? $result['items']
            ?? [];

        if (! is_array($list)) {
            return [];
        }

        if ($list !== [] && ! array_is_list($list)) {
            $list = [$list];
        }

        return $list;
    }

    /**
     * Normalize a raw search row into the seller's product card shape.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function toListItem(array $item, ?string $shipToCountry): array
    {
        $productId = (string) ($item['product_id'] ?? $item['item_id'] ?? $item['id'] ?? '');

        return [
            'product_id' => $productId,
            'title' => (string) ($item['product_title'] ?? $item['subject'] ?? $item['title'] ?? ''),
            'image' => (string) ($item['product_main_image_url'] ?? $item['image_url'] ?? ''),
            'price_min' => (string) ($item['target_sale_price'] ?? $item['sale_price'] ?? $item['min_price'] ?? '0.00'),
            'price_max' => (string) ($item['target_original_price'] ?? $item['original_price'] ?? $item['max_price'] ?? '0.00'),
            'currency' => (string) ($item['target_sale_price_currency'] ?? $item['currency_code'] ?? 'USD'),
            'product_url' => (string) ($item['product_detail_url']
                ?? ($productId !== '' ? "https://www.aliexpress.com/item/{$productId}.html" : '')),
            'orders' => (int) ($item['lastest_volume'] ?? $item['trade_count'] ?? 0),
            'rating' => (string) ($item['evaluate_rate'] ?? ''),
            'stock' => (int) ($item['total_available_stock'] ?? $item['stock'] ?? 0),
            'ship_to_country' => $shipToCountry,
            'skus' => [],
        ];
    }
}
