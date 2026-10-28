<?php

namespace App\DTOs\AliExpress;

use App\DTOs\ShippingOptionDTO;
use App\Exceptions\AliExpress\AliExpressApiException;

/**
 * A single AliExpress shipping method with its real-time freight cost for a
 * specific destination country.
 *
 * API: aliexpress.social.product.freight.query
 *
 * Documented response (result = FreightItemOpenApiVo):
 *   result.company                       — e.g. "AliExpress Standard Shipping"
 *   result.freight_amount                — cost, returned as a STRING ("0")
 *   result.currency                      — ISO currency of freight_amount
 *   result.delivery_date                 — estimated delivery date
 *   result.commit_day                    — dispatch lead time
 *   result.send_goods_country_full_name  — supplier origin
 *   result.is_hba_service                — AliExpress "HBA" handling flag
 *   result.tracking_code                 — tracking supported
 *
 * NOTE: the platform frequently returns the literal string "null" (not JSON
 * null) for absent nullable fields, so every accessor is defensive.
 */
final readonly class AliExpressFreightDTO
{
    public function __construct(
        public string $company,
        public float $cost,
        public string $currency,
        public ?string $deliveryDate = null,
        public ?string $commitDay = null,
        public ?string $shipFromCountry = null,
        public bool $trackingSupported = false,
        public bool $isHbaService = false,
    ) {}

    /**
     * Build from a single FreightItemOpenApiVo node.
     *
     * @param  array<string, mixed>  $node  the `result` object
     *
     * @throws AliExpressApiException when the node carries no usable carrier
     */
    public static function fromFreightNode(array $node): self
    {
        $company = self::nullableString($node['company'] ?? $node['service_name'] ?? $node['shipping_method'] ?? 'AliExpress Standard Shipping');

        $rawAmount = 0;
        if (isset($node['freight']['amount'])) {
            $rawAmount = $node['freight']['amount'];
        } elseif (isset($node['freight_amount'])) {
            $rawAmount = is_array($node['freight_amount']) ? ($node['freight_amount']['amount'] ?? 0) : $node['freight_amount'];
        } elseif (isset($node['amount'])) {
            $rawAmount = is_array($node['amount']) ? ($node['amount']['amount'] ?? 0) : $node['amount'];
        } elseif (isset($node['freight'])) {
            $rawAmount = is_array($node['freight']) ? ($node['freight']['amount'] ?? 0) : $node['freight'];
        } elseif (isset($node['price'])) {
            $rawAmount = is_array($node['price']) ? ($node['price']['amount'] ?? 0) : $node['price'];
        }

        $currency = null;
        if (isset($node['freight']['currency']['currency_code'])) {
            $currency = $node['freight']['currency']['currency_code'];
        } elseif (isset($node['currency'])) {
            $currency = is_array($node['currency']) ? ($node['currency']['currency_code'] ?? 'USD') : $node['currency'];
        } elseif (isset($node['currency_code'])) {
            $currency = $node['currency_code'];
        }

        return new self(
            company: $company ?? 'AliExpress Standard Shipping',
            cost: round((float) $rawAmount, 2),
            currency: self::nullableString($currency) ?? 'USD',
            deliveryDate: self::nullableString($node['delivery_date'] ?? $node['estimated_delivery_time'] ?? $node['delivery_time'] ?? null),
            commitDay: self::nullableString($node['commit_day'] ?? null),
            shipFromCountry: self::nullableString($node['send_goods_country_full_name'] ?? $node['send_goods_country_code'] ?? null) ?? 'CN',
            trackingSupported: (bool) ($node['tracking_code'] ?? $node['tracking'] ?? true),
            isHbaService: (bool) ($node['is_hba_service'] ?? false),
        );
    }

    /**
     * Build the collection of available shipping methods for a product.
     *
     * AliExpress returns a single `result` node per call. Some app tiers return
     * a list of nodes under `result.result_obj` / `result.shipping_info` when
     * several carriers are quoted; all three shapes are supported.
     *
     * @return array<int, self>
     */
    public static function collectionFromResponse(array $response): array
    {
        $result = $response['result'] ?? $response;

        if (! is_array($result) || $result === []) {
            return [];
        }

        $nodes = self::extractNodes($result);

        $methods = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            try {
                $methods[] = self::fromFreightNode($node);
            } catch (AliExpressApiException $e) {
                // A malformed carrier quote must not fail the whole import.
                continue;
            }
        }

        return $methods;
    }

    /**
     * Cheapest quote for a product, or null when nothing is deliverable.
     */
    public static function cheapest(array $methods): ?self
    {
        if ($methods === []) {
            return null;
        }

        usort($methods, fn (self $a, self $b) => $a->cost <=> $b->cost);

        return $methods[0];
    }

    /**
     * Map AliExpress carrier quotes onto the marketplace's canonical shipping
     * option keys, reusing the existing ShippingOptionDTO contract.
     *
     * @param  array<int, self>  $methods
     * @return array<int, array<string, mixed>>
     */
    public static function toShippingOptions(array $methods): array
    {
        return array_map(self::optionFor(...), $methods);
    }

    /**
     * The product's landed supplier shipping cost: the cheapest quote.
     * Returns null when the product is not deliverable to the destination.
     */
    public static function cheapestCost(array $methods): ?float
    {
        return self::cheapest($methods)?->cost;
    }

    private static function optionFor(self $method): array
    {
        $normalized = mb_strtolower($method->company);

        $key = match (true) {
            str_contains($normalized, 'express'), str_contains($normalized, 'priority') => ShippingOptionDTO::EXPRESS_SHIPPING,
            str_contains($normalized, 'standard'), str_contains($normalized, 'regular') => ShippingOptionDTO::STANDARD_SHIPPING,
            str_contains($normalized, 'econom'), str_contains($normalized, 'economy') => ShippingOptionDTO::STANDARD_SHIPPING,
            default => ShippingOptionDTO::STANDARD_SHIPPING,
        };

        $option = ShippingOptionDTO::make($key, $method->cost);

        return $option->toArray() + [
            'carrier' => $method->company,
            'currency' => $method->currency,
            'estimated_delivery' => $method->deliveryDate ?? $option->estimatedDelivery,
            'commit_day' => $method->commitDay,
            'ship_from_country' => $method->shipFromCountry,
            'tracking_supported' => $method->trackingSupported,
        ];
    }

    /**
     * Normalise the response's many possible container shapes into a flat
     * list of candidate freight nodes.
     *
     * @return array<int, mixed>
     */
    private static function extractNodes(array $result): array
    {
        // Single-object shape (documented): {company: ..., freight_amount: ...}
        if (isset($result['company']) || isset($result['freight_amount']) || isset($result['service_name'])) {
            return [$result];
        }

        $containerKeys = [
            'aeop_freight_calculate_result_for_buyer_d_t_o_list',
            'aeop_freight_calculate_result_for_buyer_d_t_o',
            'freight_result_list',
            'aeop_freight_fee_d_t_o',
            'result_obj',
            'shipping_info',
            'shipping_method_list',
            'freight_list',
            'shipping_method',
            'result',
        ];

        foreach ($containerKeys as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                $sub = self::extractNodes($result[$key]);
                if (! empty($sub)) {
                    return $sub;
                }
            }
        }

        if (array_is_list($result) && ! empty($result) && is_array($result[0])) {
            return $result;
        }

        return [];
    }

    /**
     * The platform returns the literal string "null" for absent values.
     */
    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        return (string) $value;
    }
}
