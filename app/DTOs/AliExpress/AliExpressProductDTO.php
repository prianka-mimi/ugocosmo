<?php

namespace App\DTOs\AliExpress;

/**
 * A product fetched from the AliExpress Open Platform.
 *
 * Supports both the compact list response (aliexpress.merchant.products.list)
 * and the full detail response (aliexpress.merchant.products.query).
 * All field access is defensive: response shapes vary by app tier.
 */
final readonly class AliExpressProductDTO
{
    /**
     * @param  array<int, AliExpressSkuDTO>  $skus
     * @param  array<int, string>  $images
     */
    public function __construct(
        public string $productId,
        public string $subject,
        public ?string $description,
        public array $images,
        public ?string $mainImage,
        public ?string $priceMin,
        public ?string $priceMax,
        public ?string $currency,
        public ?string $categoryId,
        public ?string $categoryName,
        public array $skus,
        /** @var array<int, array{name: string, value: string}> */
        public array $attributes,
        public ?string $brand,
        public ?string $productUrl,
        public ?string $productStatusType,
        public ?string $ownerMemberId,
        public ?string $gmtModified,
        public int $totalStock = 0,
        public int $orders = 0,
        public ?string $rating = null,
        public int $reviews = 0,
        public ?string $reviewsDesc = null,
        public ?string $sold = null,
        public array $videos = [],
        public ?array $store = null,
        public ?array $package = null,
        public ?array $logistics = null,
        public ?array $evaluation = null,
        public ?array $shipping = null,
        public ?array $raw = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $productId = (string) ($data['product_id'] ?? $data['id'] ?? '');
        $subject = (string) ($data['subject'] ?? $data['title'] ?? $data['name'] ?? "AliExpress Product {$productId}");
        $images = isset($data['images']) ? (array) $data['images'] : (isset($data['image']) ? [$data['image']] : []);
        $price = (string) ($data['price'] ?? $data['price_min'] ?? '0.00');

        return new self(
            productId: $productId,
            subject: $subject,
            description: $data['description'] ?? null,
            images: $images,
            mainImage: $images[0] ?? null,
            priceMin: (string) ($data['price_min'] ?? $price),
            priceMax: (string) ($data['price_max'] ?? $price),
            currency: $data['currency'] ?? 'USD',
            categoryId: isset($data['category_id']) ? (string) $data['category_id'] : null,
            categoryName: $data['category_name'] ?? null,
            skus: $data['skus'] ?? $data['variants'] ?? [],
            attributes: $data['attributes'] ?? [],
            brand: $data['brand'] ?? null,
            productUrl: $data['product_url'] ?? self::buildProductUrl($productId),
            productStatusType: $data['product_status_type'] ?? null,
            ownerMemberId: $data['owner_member_id'] ?? null,
            gmtModified: $data['gmt_modified'] ?? null,
            totalStock: (int) ($data['stock'] ?? $data['stock_quantity'] ?? 100),
            orders: (int) ($data['orders'] ?? 0),
            rating: isset($data['rating']) ? (string) $data['rating'] : null,
            reviews: (int) ($data['reviews'] ?? 0),
            reviewsDesc: $data['reviews_desc'] ?? null,
            sold: $data['sold'] ?? null,
            videos: $data['videos'] ?? [],
            store: $data['store'] ?? null,
            package: $data['package'] ?? null,
            logistics: $data['logistics'] ?? null,
            evaluation: $data['evaluation'] ?? null,
            shipping: $data['shipping'] ?? null,
            raw: $data,
        );
    }

    /**
     * Build a full DTO from the AliExpress DS (Dropshipping) product API response.
     * API method: aliexpress.ds.product.get
     *
     * Official response structure:
     *   result.ae_item_base_info_dto   — product title, category, status
     *   result.ae_multimedia_info_dto  — image_urls (semicolon-separated)
     *   result.ae_item_sku_info_dtos[] — SKU array (direct, not nested)
     *   result.ae_item_properties[]    — product attributes
     */
    public static function fromDsResponse(array $result): self
    {
        $baseInfo = $result['ae_item_base_info_dto'] ?? [];
        $productId = (string) ($baseInfo['product_id'] ?? $result['product_id'] ?? '');

        // Images from ae_multimedia_info_dto.image_urls (semicolon-separated string)
        $images = [];
        $imageUrls = $result['ae_multimedia_info_dto']['image_urls'] ?? '';
        if ($imageUrls !== '') {
            $images = self::parseImages($imageUrls);
        }

        // Videos from ae_multimedia_info_dto.ae_video_dtos
        $videos = [];
        $rawVideos = $result['ae_multimedia_info_dto']['ae_video_dtos']['ae_video_d_t_o']
            ?? $result['ae_multimedia_info_dto']['ae_video_dtos']
            ?? [];
        if (! array_is_list($rawVideos) && ! empty($rawVideos)) {
            $rawVideos = [$rawVideos];
        }
        foreach ($rawVideos as $v) {
            if (is_array($v)) {
                $videos[] = [
                    'video_id' => (string) ($v['video_id'] ?? ''),
                    'media_type' => (string) ($v['media_type'] ?? 'mp4'),
                    'poster_url' => (string) ($v['poster_url'] ?? ''),
                    'video_url' => (string) ($v['video_url'] ?? ''),
                    'status' => (string) ($v['video_status'] ?? ''),
                ];
            }
        }

        // Store info from ae_store_info
        $storeInfo = $result['ae_store_info'] ?? $result['store_info'] ?? $result['seller_info'] ?? [];
        $store = ! empty($storeInfo) ? [
            'store_id' => (string) ($storeInfo['store_id'] ?? ''),
            'store_name' => (string) ($storeInfo['store_name'] ?? ''),
            'store_url' => (string) ($storeInfo['store_url'] ?? ''),
            'country_code' => (string) ($storeInfo['store_country_code'] ?? $storeInfo['country'] ?? ''),
            'positive_feedback_rate' => (string) ($storeInfo['positive_feedback_rate'] ?? ''),
            'item_as_described' => (string) ($storeInfo['item_as_described_rating'] ?? ''),
            'communication' => (string) ($storeInfo['communication_rating'] ?? ''),
            'shipping_speed' => (string) ($storeInfo['shipping_speed_rating'] ?? ''),
        ] : null;

        // Packaging info
        $pkgInfo = $result['package_info_dto'] ?? [];
        $package = ! empty($pkgInfo) ? [
            'length' => (float) ($pkgInfo['package_length'] ?? 0),
            'width' => (float) ($pkgInfo['package_width'] ?? 0),
            'height' => (float) ($pkgInfo['package_height'] ?? 0),
            'weight' => (float) ($pkgInfo['gross_weight'] ?? 0),
        ] : null;

        // Logistics info
        $logisticsInfo = $result['logistics_info_dto'] ?? [];
        $logistics = ! empty($logisticsInfo) ? [
            'delivery_time' => (int) ($logisticsInfo['delivery_time'] ?? 0),
            'ship_to_country' => (string) ($logisticsInfo['ship_to_country'] ?? ''),
        ] : null;

        // SKUs: ae_item_sku_info_dtos is a direct array per official docs
        $skus = [];
        $skuList = $result['ae_item_sku_info_dtos'] ?? [];
        if (! array_is_list($skuList) && ! empty($skuList)) {
            $skuList = [$skuList];
        }
        foreach ($skuList as $sku) {
            if (is_array($sku)) {
                $skus[] = AliExpressSkuDTO::fromDsResponse($sku);
            }
        }

        // Price: min/max from SKU offer_sale_price
        $skuPrices = array_filter(array_map(
            fn (AliExpressSkuDTO $s) => $s->skuPrice !== null ? (float) $s->skuPrice : null,
            $skus
        ));
        $priceMin = $skuPrices ? (string) min($skuPrices) : null;
        $priceMax = $skuPrices ? (string) max($skuPrices) : null;

        // Currency from first SKU
        $currency = ! empty($skuList[0]['currency_code'])
            ? (string) $skuList[0]['currency_code']
            : 'USD';

        // Attributes from ae_item_properties
        $attributes = [];
        foreach ((array) ($result['ae_item_properties'] ?? []) as $prop) {
            if (! is_array($prop)) {
                continue;
            }
            $name = (string) ($prop['attr_name'] ?? '');
            $value = (string) ($prop['attr_value'] ?? '');
            if ($name !== '' && $value !== '') {
                $attributes[] = [
                    'name' => $name,
                    'value' => $value,
                    'attr_name_id' => $prop['attr_name_id'] ?? null,
                    'attr_value_id' => $prop['attr_value_id'] ?? null,
                ];
            }
        }

        $brand = null;
        foreach ($attributes as $attr) {
            if (strtolower($attr['name']) === 'brand name' || strtolower($attr['name']) === 'brand') {
                $brand = $attr['value'];
                break;
            }
        }

        $totalStock = array_sum(array_map(fn (AliExpressSkuDTO $s) => $s->stock, $skus));
        $orders = (int) ($baseInfo['lastest_volume'] ?? $baseInfo['sales_count'] ?? 0);
        $evaluationCount = (int) ($baseInfo['evaluation_count'] ?? 0);

        return new self(
            productId: $productId,
            subject: (string) ($baseInfo['subject'] ?? ''),
            description: self::cleanHtmlDescription($baseInfo['detail'] ?? null),
            images: $images,
            mainImage: $images[0] ?? null,
            priceMin: $priceMin,
            priceMax: $priceMax,
            currency: $currency,
            categoryId: $categoryId = self::nullableString((string) ($baseInfo['category_id'] ?? '')),
            categoryName: self::nullableString($baseInfo['category_name'] ?? $result['category_name'] ?? null)
                ?? ($categoryId !== null ? self::resolveKnownCategoryName($categoryId) : null),
            skus: $skus,
            attributes: $attributes,
            brand: $brand,
            productUrl: self::buildProductUrl($productId),
            productStatusType: self::nullableString($baseInfo['product_status_type'] ?? null),
            ownerMemberId: null,
            gmtModified: null,
            totalStock: $totalStock,
            orders: $orders,
            rating: self::nullableString($baseInfo['avg_evaluation_rating'] ?? null),
            reviews: $evaluationCount,
            reviewsDesc: $evaluationCount > 0 ? number_format($evaluationCount).' Reviews' : '0 Reviews',
            sold: $orders > 0 ? ($orders >= 1000 ? number_format((int) (floor($orders / 1000) * 1000)).'+ sold' : $orders.' sold') : '0 sold',
            videos: $videos,
            store: $store,
            package: $package,
            logistics: $logistics,
            evaluation: null,
            shipping: null,
            raw: $result,
        );
    }

    /**
     * Extract the numeric AliExpress product ID from a product URL or raw ID string.
     * Supports: https://www.aliexpress.com/item/1005001234567.html
     *           https://aliexpress.com/item/1005001234567.html
     *           1005001234567
     */
    public static function extractProductId(string $urlOrId): ?string
    {
        $urlOrId = trim($urlOrId);

        // Raw numeric ID
        if (ctype_digit($urlOrId)) {
            return $urlOrId;
        }

        // URL pattern: /item/{id}.html
        if (preg_match('#/item/(\d+)\.html#i', $urlOrId, $m)) {
            return $m[1];
        }

        // Query param: ?productId=...
        $parsed = parse_url($urlOrId);
        if (! empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            if (! empty($params['productId']) && ctype_digit((string) $params['productId'])) {
                return (string) $params['productId'];
            }
        }

        return null;
    }

    /**
     * Build a lightweight preview from the product list response.
     */
    public static function fromListEntry(array $entry): self
    {
        $images = self::parseImages($entry['image_urls'] ?? null);
        $productId = (string) ($entry['product_id'] ?? '');

        return new self(
            productId: $productId,
            subject: (string) ($entry['subject'] ?? ''),
            description: null,
            images: $images,
            mainImage: $images[0] ?? null,
            priceMin: self::nullableString($entry['product_min_price'] ?? null),
            priceMax: self::nullableString($entry['product_max_price'] ?? null),
            currency: self::nullableString($entry['currency_code'] ?? null),
            categoryId: self::nullableString($entry['category_id'] ?? null),
            categoryName: null,
            skus: [],
            attributes: [],
            brand: null,
            productUrl: self::buildProductUrl($productId),
            productStatusType: self::nullableString($entry['product_status_type'] ?? null),
            ownerMemberId: self::nullableString($entry['owner_member_id'] ?? null),
            gmtModified: self::nullableString($entry['gmt_modified'] ?? null),
            totalStock: 0,
            orders: (int) ($entry['orders'] ?? 0),
            rating: self::nullableString($entry['rating'] ?? null),
            reviews: (int) ($entry['reviews'] ?? 0),
            reviewsDesc: self::nullableString($entry['reviews_desc'] ?? null),
            sold: self::nullableString($entry['sold'] ?? null),
        );
    }

    /**
     * Build a full DTO from the merchant product query (detail) response.
     */
    public static function fromMerchantDetail(array $result): self
    {
        $images = self::parseImages($result['image_urls'] ?? null);
        if ($images === [] && isset($result['aeop_ae_multimedia']['image_urls']) && is_string($result['aeop_ae_multimedia']['image_urls'])) {
            $images = self::parseImages($result['aeop_ae_multimedia']['image_urls']);
        }
        if ($images === [] && isset($result['aeop_ae_multimedia']['image_urls']) && is_array($result['aeop_ae_multimedia']['image_urls'])) {
            $images = self::parseImages(implode(';', $result['aeop_ae_multimedia']['image_urls']));
        }

        $productId = (string) ($result['product_id'] ?? '');

        $skus = [];
        foreach ((array) ($result['aeop_ae_product_skus'] ?? []) as $sku) {
            if (is_array($sku)) {
                $skus[] = AliExpressSkuDTO::fromMerchantResponse($sku);
            }
        }

        $attributes = [];
        foreach ((array) ($result['aeop_ae_product_propertys'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }
            $name = (string) ($attribute['attribute_name'] ?? '');
            $value = (string) ($attribute['attribute_value'] ?? '');
            if ($name !== '' || $value !== '') {
                $attributes[] = ['name' => $name, 'value' => $value];
            }
        }

        $brand = null;
        foreach ($attributes as $attribute) {
            if (mb_strtolower($attribute['name']) === 'brand') {
                $brand = $attribute['value'];
                break;
            }
        }

        $pkgInfo = $result['package_info_dto'] ?? [];
        $package = ! empty($pkgInfo) ? [
            'length' => (float) ($pkgInfo['package_length'] ?? 0),
            'width' => (float) ($pkgInfo['package_width'] ?? 0),
            'height' => (float) ($pkgInfo['package_height'] ?? 0),
            'weight' => (float) ($pkgInfo['gross_weight'] ?? 0),
        ] : (isset($result['package_length']) || isset($result['gross_weight']) ? [
            'length' => (float) ($result['package_length'] ?? 0),
            'width' => (float) ($result['package_width'] ?? 0),
            'height' => (float) ($result['package_height'] ?? 0),
            'weight' => (float) ($result['gross_weight'] ?? 0),
        ] : null);

        $logisticsInfo = $result['logistics_info_dto'] ?? [];
        $logistics = ! empty($logisticsInfo) ? [
            'delivery_time' => (int) ($logisticsInfo['delivery_time'] ?? ($result['delivery_time'] ?? 0)),
            'ship_to_country' => (string) ($logisticsInfo['ship_to_country'] ?? ($result['ship_to_country'] ?? '')),
        ] : (isset($result['delivery_time']) ? [
            'delivery_time' => (int) $result['delivery_time'],
            'ship_to_country' => (string) ($result['ship_to_country'] ?? ''),
        ] : null);

        return new self(
            productId: $productId,
            subject: (string) ($result['subject'] ?? ''),
            description: self::cleanHtmlDescription($result['description'] ?? null),
            images: $images,
            mainImage: $images[0] ?? null,
            priceMin: self::nullableString($result['product_min_price'] ?? null),
            priceMax: self::nullableString($result['product_max_price'] ?? null),
            currency: self::nullableString($result['currency_code'] ?? null),
            categoryId: self::nullableString($result['category_id'] ?? null),
            categoryName: self::nullableString($result['category_name'] ?? null),
            skus: $skus,
            attributes: $attributes,
            brand: $brand,
            productUrl: self::buildProductUrl($productId),
            productStatusType: self::nullableString($result['product_status_type'] ?? null),
            ownerMemberId: self::nullableString($result['owner_member_id'] ?? null),
            gmtModified: self::nullableString($result['gmt_modified'] ?? null),
            orders: $orders = (int) ($result['orders'] ?? $result['latest_volume'] ?? 0),
            rating: self::nullableString($result['rating'] ?? $result['avg_evaluation_rating'] ?? null),
            reviews: $evaluationCount = (int) ($result['evaluation_count'] ?? 0),
            reviewsDesc: $evaluationCount > 0 ? number_format($evaluationCount).' Reviews' : '0 Reviews',
            sold: $orders > 0 ? ($orders >= 1000 ? number_format((int) (floor($orders / 1000) * 1000)).'+ sold' : $orders.' sold') : '0 sold',
            videos: [],
            store: null,
            package: $package,
            logistics: $logistics,
            evaluation: null,
            shipping: null,
            raw: $result,
        );
    }

    /**
     * Copy of this product carrying refreshed SKUs (real-time stock overlay).
     *
     * Keeps every other field intact so the product-detail response remains
     * the single source of truth for title, images, description and pricing.
     *
     * @param  array<int, AliExpressSkuDTO>  $skus
     */
    public function withSkus(array $skus): self
    {
        return new self(
            productId: $this->productId,
            subject: $this->subject,
            description: $this->description,
            images: $this->images,
            mainImage: $this->mainImage,
            priceMin: $this->priceMin,
            priceMax: $this->priceMax,
            currency: $this->currency,
            categoryId: $this->categoryId,
            categoryName: $this->categoryName,
            skus: $skus,
            attributes: $this->attributes,
            brand: $this->brand,
            productUrl: $this->productUrl,
            productStatusType: $this->productStatusType,
            ownerMemberId: $this->ownerMemberId,
            gmtModified: $this->gmtModified,
            totalStock: array_sum(array_map(fn (AliExpressSkuDTO $s) => $s->stock, $skus)),
            orders: $this->orders,
            rating: $this->rating,
            reviews: $this->reviews,
            reviewsDesc: $this->reviewsDesc,
            sold: $this->sold,
            videos: $this->videos,
            store: $this->store,
            package: $this->package,
            logistics: $this->logistics,
            evaluation: $this->evaluation,
            shipping: $this->shipping,
            raw: $this->raw,
        );
    }

    /**
     * Copy of this product carrying rich evaluation and review metrics.
     *
     * @param  array<string, mixed>  $evaluation
     */
    public function withEvaluation(array $evaluation): self
    {
        $rating = ! empty($evaluation['rating']) ? (string) $evaluation['rating'] : $this->rating;
        $reviews = isset($evaluation['total_reviews']) && $evaluation['total_reviews'] > 0
            ? (int) $evaluation['total_reviews']
            : $this->reviews;
        $reviewsDesc = $reviews > 0 ? number_format($reviews).' Reviews' : $this->reviewsDesc;

        return new self(
            productId: $this->productId,
            subject: $this->subject,
            description: $this->description,
            images: $this->images,
            mainImage: $this->mainImage,
            priceMin: $this->priceMin,
            priceMax: $this->priceMax,
            currency: $this->currency,
            categoryId: $this->categoryId,
            categoryName: $this->categoryName,
            skus: $this->skus,
            attributes: $this->attributes,
            brand: $this->brand,
            productUrl: $this->productUrl,
            productStatusType: $this->productStatusType,
            ownerMemberId: $this->ownerMemberId,
            gmtModified: $this->gmtModified,
            totalStock: $this->totalStock,
            orders: $this->orders,
            rating: $rating,
            reviews: $reviews,
            reviewsDesc: $reviewsDesc,
            sold: $this->sold,
            videos: $this->videos,
            store: $this->store,
            package: $this->package,
            logistics: $this->logistics,
            evaluation: $evaluation,
            shipping: $this->shipping,
            raw: $this->raw,
        );
    }

    /**
     * Copy of this product carrying authentic shipping details.
     *
     * @param  array<string, mixed>  $shipping
     */
    public function withShipping(array $shipping): self
    {
        return new self(
            productId: $this->productId,
            subject: $this->subject,
            description: $this->description,
            images: $this->images,
            mainImage: $this->mainImage,
            priceMin: $this->priceMin,
            priceMax: $this->priceMax,
            currency: $this->currency,
            categoryId: $this->categoryId,
            categoryName: $this->categoryName,
            skus: $this->skus,
            attributes: $this->attributes,
            brand: $this->brand,
            productUrl: $this->productUrl,
            productStatusType: $this->productStatusType,
            ownerMemberId: $this->ownerMemberId,
            gmtModified: $this->gmtModified,
            totalStock: $this->totalStock,
            orders: $this->orders,
            rating: $this->rating,
            reviews: $this->reviews,
            reviewsDesc: $this->reviewsDesc,
            sold: $this->sold,
            videos: $this->videos,
            store: $this->store,
            package: $this->package ?? ($shipping['package'] ?? null),
            logistics: $this->logistics ?? ($shipping['logistics'] ?? null),
            evaluation: $this->evaluation,
            shipping: $shipping,
            raw: $this->raw,
        );
    }

    /**
     * The AliExpress SKU identifier that represents this listing, used for
     * future supplier synchronization (order placement, stock sync).
     *
     * Prefers the first SKU's supplier-side code (sku_attr) because that is
     * what aliexpress.ds.order.create requires as `sku_attr`; falls back to
     * the numeric sku_id.
     */
    public function primarySku(): ?string
    {
        $first = $this->skus[0] ?? null;

        if ($first === null) {
            return null;
        }

        return $first->skuCode ?: ($first->skuId !== '' ? $first->skuId : null);
    }

    /**
     * Total real-time supplier stock across every SKU.
     */
    public function liveStock(): int
    {
        return array_sum(array_map(fn (AliExpressSkuDTO $s) => $s->stock, $this->skus));
    }

    /**
     * The seller's cost basis (lowest SKU price, fallback to price_min).
     */
    public function costPrice(): float
    {
        $prices = array_map(
            fn (AliExpressSkuDTO $sku) => (float) $sku->skuPrice,
            array_filter($this->skus, fn (AliExpressSkuDTO $sku) => $sku->skuPrice !== null)
        );

        if ($prices !== []) {
            return min($prices);
        }

        $min = (float) ($this->priceMin ?? 0);
        $max = (float) ($this->priceMax ?? 0);

        return $min > 0 ? $min : $max;
    }

    /**
     * Build the canonical AliExpress item URL.
     */
    private static function buildProductUrl(string $productId): ?string
    {
        return $productId !== ''
            ? "https://www.aliexpress.com/item/{$productId}.html"
            : null;
    }

    /**
     * AliExpress returns image URLs as ';'-separated strings.
     */
    private static function parseImages(mixed $value): array
    {
        $images = [];
        if (is_string($value) && $value !== '') {
            $images = array_values(array_filter(array_map('trim', explode(';', $value))));
        } elseif (is_array($value)) {
            foreach ($value as $image) {
                if (is_string($image) && $image !== '') {
                    $images[] = $image;
                }
            }
        }

        return array_slice($images, 0, 10);
    }

    /**
     * Resolve known standard AliExpress category IDs to human-readable names.
     */
    public static function resolveKnownCategoryName(string $categoryId): ?string
    {
        return match (trim($categoryId)) {
            '380230' => 'Phone Bags & Cases',
            '509' => 'Phones & Telecommunications',
            '100001205' => 'Mobile Phone Cases',
            '100001206' => 'Screen Protectors',
            '200003093' => 'Mobile Phone Cables',
            '200003094' => 'Mobile Phone Chargers',
            '200002120' => 'Mobile Phone Holders & Stands',
            '1509' => 'Consumer Electronics',
            '502' => 'Electronic Components & Supplies',
            '18' => 'Sports & Entertainment',
            '34' => 'Automobiles & Motorcycles',
            '66' => 'Beauty & Health',
            '26' => 'Toys & Hobbies',
            '15' => 'Home & Garden',
            '1503' => 'Luggage & Bags',
            '1511' => 'Shoes',
            '200000345' => "Men's Clothing",
            '200000343' => "Women's Clothing",
            '1524' => 'Mother & Kids',
            '21' => 'Office & School Supplies',
            '6' => 'Home Improvement',
            '39' => 'Lights & Lighting',
            '1420' => 'Tools',
            '322' => 'Jewelry & Accessories',
            '1501' => 'Watches',
            '13' => 'Computer & Office',
            '200216599' => 'Home Appliances',
            '200000297' => 'Security & Protection',
            '200000137' => 'Extensions & Wigs',
            '200003482' => 'Special Occasion Costumes',
            default => null,
        };
    }

    /**
     * Resolve known category names to standard AliExpress category IDs.
     */
    public static function resolveKnownCategoryId(string $categoryName): ?string
    {
        $name = strtolower(trim($categoryName));
        $map = [
            'phone bags & cases' => '380230',
            'phone bags and cases' => '380230',
            'phone cases' => '380230',
            'phones & telecommunications' => '509',
            'phones and telecommunications' => '509',
            'mobile phone cases' => '100001205',
            'screen protectors' => '100001206',
            'mobile phone cables' => '200003093',
            'mobile phone chargers' => '200003094',
            'mobile phone holders & stands' => '200002120',
            'consumer electronics' => '1509',
            'electronics' => '1509',
            'electronic components & supplies' => '502',
            'sports & entertainment' => '18',
            'sports' => '18',
            'automobiles & motorcycles' => '34',
            'automotive' => '34',
            'beauty & health' => '66',
            'beauty' => '66',
            'toys & hobbies' => '26',
            'toys' => '26',
            'home & garden' => '15',
            'home and garden' => '15',
            'luggage & bags' => '1503',
            'bags' => '1503',
            'shoes' => '1511',
            "men's clothing" => '200000345',
            'mens clothing' => '200000345',
            "women's clothing" => '200000343',
            'womens clothing' => '200000343',
            'mother & kids' => '1524',
            'office & school supplies' => '21',
            'home improvement' => '6',
            'lights & lighting' => '39',
            'lighting' => '39',
            'tools' => '1420',
            'jewelry & accessories' => '322',
            'jewelry' => '322',
            'watches' => '1501',
            'computer & office' => '13',
            'computers' => '13',
            'home appliances' => '200216599',
            'appliances' => '200216599',
            'security & protection' => '200000297',
            'extensions & wigs' => '200000137',
            'special occasion costumes' => '200003482',
        ];

        return $map[$name] ?? null;
    }

    /**
     * Clean HTML description by decoding entities, replacing break/block tags with newlines,
     * stripping all HTML/XML tags, and collapsing whitespace so no raw tags remain.
     */
    public static function cleanHtmlDescription(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // 1. Decode HTML entities (e.g. &nbsp;, &amp;, &quot;, &lt;, &gt;, &#39;)
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Remove script and style tags completely along with their contents
        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
        $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $text);

        // 3. Convert line-breaks and closing block tags to newlines so paragraphs don't run together
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr|table|ul|ol|header|section|article)>/i', "\n", $text);

        // 4. Strip ALL remaining HTML and XML tags completely (e.g. <img ...>, <span>, <p>, etc.)
        $text = strip_tags($text);

        // 5. Replace non-breaking spaces and unicode spaces with standard space
        $text = str_replace(["\xc2\xa0", "\u{00A0}"], ' ', $text);

        // 6. Split by newlines, trim lines, collapse multiple whitespace
        $lines = explode("\n", $text);
        $cleanedLines = [];
        foreach ($lines as $line) {
            $trimmed = trim(preg_replace('/[ \t]+/', ' ', $line));
            if ($trimmed !== '') {
                $cleanedLines[] = $trimmed;
            }
        }

        return implode("\n", $cleanedLines);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
